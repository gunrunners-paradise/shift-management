<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProfileService
{
    public function __construct(private readonly AvatarStorage $avatars) {}

    /**
     * Update only the authenticated user's personal fields and optional photo.
     * The user row lock serializes this write with deactivation and concurrent
     * photo replacements. Database rollback never deletes the previous photo.
     * The HTTP action calls this without another application transaction around
     * it, so this method can clean up a staged file if its transaction fails.
     */
    public function update(User $actor, array $attributes, ?UploadedFile $photo = null): User
    {
        $newPhotoPath = null;
        $profileCommitted = false;

        try {
            if ($photo !== null) {
                $newPhotoPath = $this->avatars->store($photo);
            }

            return DB::transaction(function () use ($actor, $attributes, $newPhotoPath, &$profileCommitted): User {
                $user = $this->lockActiveUser($actor);
                $oldPhotoPath = $user->photo_path;

                $user->first_name = $attributes['first_name'];
                $user->last_name = $attributes['last_name'];
                $user->gender = $attributes['gender'];
                $user->birth_date = $attributes['birth_date'] ?? null;

                if ($newPhotoPath !== null) {
                    $user->photo_path = $newPhotoPath;
                }

                $user->save();

                if ($newPhotoPath !== null) {
                    // Each replacement has its own random filename. Cleanup
                    // therefore cannot delete a later request's chosen image.
                    DB::afterCommit(function () use ($oldPhotoPath, &$profileCommitted): void {
                        // Mark the commit before cleanup. If the filesystem or
                        // logger throws, the new file still belongs to this row.
                        $profileCommitted = true;
                        $this->avatars->delete($oldPhotoPath);
                    });
                }

                return $user;
            }, 3);
        } catch (Throwable $exception) {
            // The staged file is unused when authorization or a DB write fails.
            if (! $profileCommitted) {
                $this->avatars->delete($newPhotoPath);
            }

            throw $exception;
        }
    }

    /**
     * Verify the latest password under the user lock, replace its secure hash,
     * rotate the remember token and revoke other stored sessions atomically.
     * The controller regenerates the current session after this commit.
     */
    public function changePassword(User $actor, string $currentPassword, string $newPassword, string $currentSessionId): User
    {
        return DB::transaction(function () use ($actor, $currentPassword, $newPassword, $currentSessionId): User {
            $user = $this->lockActiveUser($actor);

            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'הסיסמה הנוכחית אינה נכונה.',
                ]);
            }

            // The model's hashed cast hashes plaintext exactly once.
            $user->password = $newPassword;
            $user->remember_token = Str::random(60);
            $user->save();

            DB::table('sessions')
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $currentSessionId)
                ->delete();

            return $user;
        }, 3);
    }

    /** The deactivation workflow uses the same user row as its first lock. */
    private function lockActiveUser(User $actor): User
    {
        $user = User::query()->lockForUpdate()->find($actor->getKey());

        // Check the login version under the same lock as the profile/password
        // write so a pre-deactivation request cannot resume after reactivation.
        if ($user === null || ! $user->is_active || ! in_array($user->role, ['worker', 'manager'], true)
            || $user->session_version !== (int) $actor->session_version) {
            throw new AuthorizationException('אין הרשאה לעדכן את הפרופיל.');
        }

        return $user;
    }
}
