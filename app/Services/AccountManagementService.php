<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountManagementService
{
    public function __construct(
        private readonly ManagerTransaction $managerTransaction,
        private readonly ShiftSchedulingService $shiftScheduling,
    ) {}

    /** Create an account after rechecking the manager inside the account lock. */
    public function create(User $actor, array $attributes): User
    {
        return $this->managerTransaction->run($actor, function () use ($attributes): User {
            $this->ensureUsernameIsAvailable($attributes['username']);

            return User::create($this->accountAttributes($attributes));
        });
    }

    /**
     * Deactivate without deleting the stable user ID, and revoke stored sessions.
     * The account lock prevents concurrent managers from removing the last one.
     * Future and in-progress shifts are canceled; completed history is retained.
     * Desk cancellation belongs to Stage 5, when desk records exist.
     */
    public function deactivate(User $actor, User $target): void
    {
        $this->managerTransaction->run($actor, function () use ($actor, $target): void {
            $account = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (! $account->is_active) {
                return;
            }

            if ($account->isManager() && User::where('role', 'manager')->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages([
                    'account' => 'לא ניתן להשבית את חשבון המנהל הפעיל האחרון.',
                ]);
            }

            if ($account->role === 'worker') {
                $this->shiftScheduling->cancelForDeactivation($account, $actor);
            }

            $account->is_active = false;
            $account->remember_token = null;
            $account->save();

            DB::table('sessions')->where('user_id', $account->getKey())->delete();
        });
    }

    /**
     * Create the first manager from validated CLI input; return null if one exists.
     * A repeat run never resets a password, even for an inactive manager.
     */
    public function bootstrap(array $attributes): ?User
    {
        return $this->managerTransaction->run(null, function () use ($attributes): ?User {
            if (User::where('role', 'manager')->exists()) {
                return null;
            }

            $this->ensureUsernameIsAvailable($attributes['username']);
            $attributes['role'] = 'manager';

            return User::create($this->accountAttributes($attributes));
        });
    }

    private function ensureUsernameIsAvailable(string $username): void
    {
        if (User::where('username', $username)->exists()) {
            throw ValidationException::withMessages(['username' => 'שם המשתמש כבר קיים במערכת.']);
        }
    }

    /** Explicitly select permitted input; no request can inject an active flag. */
    private function accountAttributes(array $attributes): array
    {
        return [
            'username' => $attributes['username'],
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'gender' => $attributes['gender'],
            'birth_date' => $attributes['birth_date'] ?? null,
            'role' => $attributes['role'],
            'password' => $attributes['password'],
            'is_active' => true,
        ];
    }
}
