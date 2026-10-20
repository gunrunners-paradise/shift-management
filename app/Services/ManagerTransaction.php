<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagerTransaction
{
    /**
     * Serialize manager writes and commit their cross-record changes together.
     * This protects the last manager, first-manager setup, date closures and
     * authority checks for event maintenance.
     * One shared lock also avoids manager cancellation-actor foreign-key locks
     * racing with account deactivation. Workers never acquire this lock.
     *
     * A null actor is reserved for the interactive first-manager bootstrap.
     * The lock name includes the schema so test and local databases are separate.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(?User $actor, callable $operation): mixed
    {
        $connection = DB::connection();
        $lockName = 'accounts:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 48);
        $result = $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);

        if ((int) $result->acquired !== 1) {
            throw ValidationException::withMessages([
                'account' => 'ניהול המערכת עסוק כרגע. נסו שוב בעוד מספר שניות.',
            ]);
        }

        try {
            return $connection->transaction(function () use ($actor, $operation): mixed {
                if ($actor !== null) {
                    // A manager may have been disabled while waiting for the lock.
                    // A locking read also avoids creating a stale REPEATABLE READ
                    // snapshot before waiting for a worker being deactivated.
                    $currentActor = User::query()->lockForUpdate()->find($actor->getKey());

                    if ($currentActor === null || ! $currentActor->is_active || ! $currentActor->isManager()) {
                        throw new AuthorizationException('אין הרשאה לניהול המערכת.');
                    }
                }

                return $operation();
            }, 3);
        } finally {
            // MariaDB named locks survive COMMIT/ROLLBACK; release after either.
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
}
