<?php

namespace App\Services;

use App\Models\PendingSignupRequest;
use App\Models\SignupSetting;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use JsonException;

class SignupService
{
    public function __construct(private ManagerTransaction $managerTransaction) {}

    /** Missing settings fail closed; reads never create or enable a switch. */
    public function isEnabled(string $role): bool
    {
        return in_array($role, ['worker', 'manager'], true)
            && SignupSetting::query()->whereKey($role)->where('is_enabled', true)->exists();
    }

    /**
     * Store validated guest input as a pending request, never as a real user.
     * The controller supplies the role from its constrained route. Hash outside
     * the account gate to keep expensive work from blocking manager actions;
     * then recheck the switch and both username namespaces under that gate.
     * A close that commits first therefore prevents even an already-open form.
     *
     * @return bool False when registration closed while the form was submitted.
     */
    public function submit(string $role, array $attributes): bool
    {
        $this->requireRole($role);
        $passwordHash = Hash::make($attributes['password']);

        return $this->managerTransaction->run(null, function () use ($role, $attributes, $passwordHash): bool {
            $setting = SignupSetting::query()->lockForUpdate()->find($role);

            if ($setting === null || ! $setting->is_enabled) {
                return false;
            }

            // Ordinary manager/CLI account creation uses this same gate. Pending
            // requests do not prevent those trusted workflows from creating a
            // real account; a later approval reports that conflict instead.
            if (User::where('username', $attributes['username'])->exists()
                || PendingSignupRequest::where('username', $attributes['username'])->exists()) {
                throw ValidationException::withMessages([
                    'username' => 'לא ניתן להשתמש בשם המשתמש הזה. יש לבחור שם משתמש אחר או לפנות למנהל/ת.',
                ]);
            }

            PendingSignupRequest::create([
                'username' => $attributes['username'],
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'gender' => $attributes['gender'],
                'birth_date' => $attributes['birth_date'] ?? null,
                'role' => $role,
                'password' => $passwordHash,
            ]);

            return true;
        });
    }

    /** Set the requested state explicitly so repeated/stale buttons never toggle it back. */
    public function setEnabled(User $actor, string $role, bool $enabled): void
    {
        $this->requireRole($role);

        $this->managerTransaction->run($actor, function () use ($role, $enabled): void {
            $setting = SignupSetting::query()->lockForUpdate()->findOrFail($role);
            $setting->is_enabled = $enabled;
            $setting->save();
        });
    }

    /**
     * Preview one request or the entire pending list, independent of pagination.
     * All request writes share the account gate. Consequently the committed
     * maximum ID identifies exactly the current immutable request cohort: later
     * submissions have larger IDs, even when an old username is resubmitted.
     * The encrypted/authenticated token binds that cohort and operation to this
     * manager's current login version for 30 minutes; it contains no password.
     */
    public function review(User $actor, string $action, ?int $requestId = null): array
    {
        if (! in_array($action, ['approve', 'delete'], true) || ($requestId !== null && $requestId < 1)) {
            throw $this->managementError('יש לבחור פעולה ובקשה תקינות.');
        }

        return $this->managerTransaction->run($actor, function () use ($actor, $action, $requestId): array {
            $selection = [
                'purpose' => 'signup-review',
                'actor_id' => (int) $actor->getKey(),
                'session_version' => (int) $actor->session_version,
                'action' => $action,
                'scope' => $requestId === null ? 'all' : 'single',
                'single_id' => $requestId,
                'max_id' => (int) (PendingSignupRequest::query()->max('id') ?? 0),
                'expires_at' => now()->addMinutes(30)->getTimestamp(),
            ];
            $counts = $this->selectedRequests($selection)->selectRaw('role, COUNT(*) as total')
                ->groupBy('role')->pluck('total', 'role');
            $selection['worker_count'] = (int) ($counts['worker'] ?? 0);
            $selection['manager_count'] = (int) ($counts['manager'] ?? 0);
            $selection['total'] = $selection['worker_count'] + $selection['manager_count'];

            if ($selection['total'] === 0) {
                throw $this->managementError('אין בקשות ממתינות לביצוע הפעולה. ייתכן שכבר טופלו.');
            }

            return [
                ...$selection,
                'name' => $requestId === null ? null : PendingSignupRequest::find($requestId)?->fullName(),
                'token' => Crypt::encryptString(json_encode($selection, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /**
     * Apply a previously reviewed operation under the actor gate and row locks.
     * Success removes only pending rows. Expected username conflicts stay in
     * that list with an explanation; unexpected failures roll back the whole
     * batch. Another manager's completed actions are skipped, never recreated.
     * Signup switches affect new submissions, not review of existing requests.
     *
     * @return array{action: string, created: int, deleted: int, conflicts: int, skipped: int}
     */
    public function process(User $actor, string $token): array
    {
        $selection = $this->readConfirmation($actor, $token);

        return $this->managerTransaction->run($actor, function () use ($selection): array {
            // Recheck expiration after waiting for another manager's transaction.
            if ($selection['expires_at'] <= now()->getTimestamp()) {
                throw $this->managementError('תוקף אישור הפעולה פג. יש לבחור את הפעולה שוב.');
            }

            $requests = $this->selectedRequests($selection)->orderBy('id')->lockForUpdate()->get();
            $result = [
                'action' => $selection['action'], 'created' => 0, 'deleted' => 0,
                'conflicts' => 0, 'skipped' => max(0, $selection['total'] - $requests->count()),
            ];

            foreach ($requests as $pending) {
                if ($selection['action'] === 'delete') {
                    $pending->delete();
                    $result['deleted']++;

                    continue;
                }

                if (User::where('username', $pending->username)->exists()) {
                    $pending->review_error = 'שם המשתמש כבר קיים במערכת. יש למחוק את הבקשה ולשלוח בקשה חדשה עם שם משתמש אחר.';
                    $pending->save();
                    $result['conflicts']++;

                    continue;
                }

                // Explicit fields prevent a pending record from supplying an
                // active flag, actor identity or unrelated account metadata.
                // User's hashed cast accepts the already generated hash without
                // hashing again, so the applicant's literal password still works.
                User::create([
                    'username' => $pending->username,
                    'first_name' => $pending->first_name,
                    'last_name' => $pending->last_name,
                    'gender' => $pending->gender,
                    'birth_date' => $pending->birth_date,
                    'role' => $pending->role,
                    'password' => $pending->password,
                    'is_active' => true,
                ]);
                $pending->delete();
                $result['created']++;
            }

            return $result;
        });
    }

    /** Select immutable identities, never a fresh set of usernames after confirmation. */
    private function selectedRequests(array $selection): Builder
    {
        $query = PendingSignupRequest::query();

        return $selection['scope'] === 'single'
            ? $query->whereKey($selection['single_id'])
            : $query->where('id', '<=', $selection['max_id']);
    }

    /** Reject altered, expired, cross-purpose or other-manager confirmation tokens safely. */
    private function readConfirmation(User $actor, string $token): array
    {
        try {
            $selection = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw $this->managementError('אישור הפעולה אינו תקין. יש לבחור את הפעולה שוב.');
        }

        if (! is_array($selection)) {
            throw $this->managementError('אישור הפעולה אינו תקין. יש לבחור את הפעולה שוב.');
        }

        foreach (['actor_id', 'session_version', 'max_id', 'expires_at', 'worker_count', 'manager_count', 'total'] as $key) {
            if (! isset($selection[$key]) || ! is_int($selection[$key]) || $selection[$key] < 0) {
                throw $this->managementError('אישור הפעולה אינו תקין. יש לבחור את הפעולה שוב.');
            }
        }

        if (($selection['purpose'] ?? null) !== 'signup-review'
            || $selection['actor_id'] !== (int) $actor->getKey()
            || $selection['session_version'] !== (int) $actor->session_version
            || ! in_array($selection['action'] ?? null, ['approve', 'delete'], true)
            || ! in_array($selection['scope'] ?? null, ['all', 'single'], true)
            || ! array_key_exists('single_id', $selection)
            || ($selection['scope'] === 'single' && (! is_int($selection['single_id']) || $selection['single_id'] < 1))
            || ($selection['scope'] === 'all' && $selection['single_id'] !== null)
            || $selection['total'] < 1
            || $selection['total'] !== $selection['worker_count'] + $selection['manager_count']) {
            throw $this->managementError('אישור הפעולה אינו תקין. יש לבחור את הפעולה שוב.');
        }

        return $selection;
    }

    private function requireRole(string $role): void
    {
        if (! in_array($role, ['worker', 'manager'], true)) {
            throw ValidationException::withMessages(['role' => 'סוג החשבון אינו תקין.']);
        }
    }

    private function managementError(string $message): ValidationException
    {
        return ValidationException::withMessages(['signup' => $message])->errorBag('signupManagement');
    }
}
