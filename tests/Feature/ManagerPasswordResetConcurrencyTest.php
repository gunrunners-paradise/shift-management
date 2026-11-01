<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use App\Services\ProfileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class ManagerPasswordResetConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const RESET_PASSWORD = 'Manager-chosen-password-84';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }
    }

    public function test_two_managers_resetting_each_others_passwords_cannot_both_use_their_old_login_authority(): void
    {
        $firstManager = User::factory()->manager()->create(['password' => 'First-manager-old-password-93']);
        $secondManager = User::factory()->manager()->create(['password' => 'Second-manager-old-password-93']);

        $results = ConcurrentProcesses::run([
            'first' => function () use ($firstManager, $secondManager): string {
                return $this->resetOutcome($firstManager, $secondManager);
            },
            'second' => function () use ($firstManager, $secondManager): string {
                return $this->resetOutcome($secondManager, $firstManager);
            },
        ]);

        $outcomes = array_values($results);
        sort($outcomes);
        $this->assertSame(['changed', 'rejected'], $outcomes);
        $firstChangedTarget = $results['first'] === 'changed';
        $this->assertSame($firstChangedTarget ? 0 : 1, $firstManager->fresh()->session_version);
        $this->assertSame($firstChangedTarget ? 1 : 0, $secondManager->fresh()->session_version);
        $this->assertSame(! $firstChangedTarget, Hash::check(self::RESET_PASSWORD, $firstManager->fresh()->password));
        $this->assertSame($firstChangedTarget, Hash::check(self::RESET_PASSWORD, $secondManager->fresh()->password));
        $this->assertTrue($firstManager->fresh()->is_active);
        $this->assertTrue($secondManager->fresh()->is_active);
    }

    #[DataProvider('operationOrders')]
    public function test_password_reset_racing_the_targets_deactivation_never_reactivates_the_account(bool $resetStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->create();
        $operations = [
            'reset' => function () use ($manager, $target): string {
                app(AccountManagementService::class)->resetPassword($manager, $target, self::RESET_PASSWORD);

                return 'changed';
            },
            'deactivate' => function () use ($otherManager, $target): string {
                app(AccountManagementService::class)->deactivate($otherManager, $target);

                return 'deactivated';
            },
        ];

        $results = ConcurrentProcesses::run($resetStartsFirst ? $operations : array_reverse($operations, true));

        $this->assertSame('changed', $results['reset']);
        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertFalse($target->fresh()->is_active);
        $this->assertSame(1, $target->fresh()->session_version);
        $this->assertTrue(Hash::check(self::RESET_PASSWORD, $target->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
    }

    #[DataProvider('operationOrders')]
    public function test_password_reset_racing_inactive_account_deletion_cannot_recreate_the_deleted_identity(bool $resetStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $operations = [
            'reset' => function () use ($manager, $target): string {
                try {
                    app(AccountManagementService::class)->resetPassword($manager, $target, self::RESET_PASSWORD);

                    return 'changed';
                } catch (ModelNotFoundException) {
                    return 'already-deleted';
                }
            },
            'delete' => function () use ($otherManager, $target): string {
                app(AccountDeletionService::class)->delete($otherManager, $target);

                return 'deleted';
            },
        ];

        $results = ConcurrentProcesses::run($resetStartsFirst ? $operations : array_reverse($operations, true));

        $this->assertContains($results['reset'], ['changed', 'already-deleted']);
        $this->assertSame('deleted', $results['delete']);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('operationOrders')]
    public function test_resetting_a_managers_password_racing_their_in_flight_reset_rechecks_the_actors_login_version(bool $resetStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->create(['password' => 'Unchanged-worker-password-93']);
        $operations = [
            'reset' => function () use ($manager, $target): string {
                return $this->resetOutcome($manager, $target);
            },
            'revoke-actor' => function () use ($otherManager, $manager): string {
                app(AccountManagementService::class)->resetPassword($otherManager, $manager, 'New-actor-password-75');

                return 'revoked';
            },
        ];

        $results = ConcurrentProcesses::run($resetStartsFirst ? $operations : array_reverse($operations, true));

        $this->assertSame('revoked', $results['revoke-actor']);
        $this->assertContains($results['reset'], ['changed', 'rejected']);
        $this->assertSame(1, $manager->fresh()->session_version);
        $this->assertSame($results['reset'] === 'changed' ? 1 : 0, $target->fresh()->session_version);
        $this->assertSame($results['reset'] === 'changed', Hash::check(self::RESET_PASSWORD, $target->fresh()->password));
        $this->assertTrue($manager->fresh()->is_active);
    }

    #[DataProvider('operationOrders')]
    public function test_a_profile_password_change_racing_the_manager_reset_cannot_overwrite_it_with_revoked_credentials(bool $resetStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['password' => 'Original-worker-password-93']);
        $operations = [
            'reset' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->resetPassword($manager, $worker, self::RESET_PASSWORD);

                return 'changed';
            },
            'profile' => function () use ($worker): string {
                try {
                    app(ProfileService::class)->changePassword(
                        $worker, 'Original-worker-password-93', 'Personally-chosen-password-75', 'worker-session',
                    );

                    return 'changed-before-reset';
                } catch (AuthorizationException|ValidationException) {
                    return 'rejected-after-reset';
                }
            },
        ];

        $results = ConcurrentProcesses::run($resetStartsFirst ? $operations : array_reverse($operations, true));

        // Either the profile commits first and is superseded, or the reset
        // commits first and the old profile request loses its login authority.
        $this->assertSame('changed', $results['reset']);
        $this->assertContains($results['profile'], ['changed-before-reset', 'rejected-after-reset']);
        $this->assertTrue(Hash::check(self::RESET_PASSWORD, $worker->fresh()->password));
        $this->assertFalse(Hash::check('Personally-chosen-password-75', $worker->fresh()->password));
        $this->assertSame(1, $worker->fresh()->session_version);
        $this->assertTrue($worker->fresh()->is_active);
    }

    public static function operationOrders(): array
    {
        return ['reset process first' => [true], 'other process first' => [false]];
    }

    /** An authorization rejection is an expected serialized business outcome. */
    private function resetOutcome(User $manager, User $target): string
    {
        try {
            app(AccountManagementService::class)->resetPassword($manager, $target, self::RESET_PASSWORD);

            return 'changed';
        } catch (AuthorizationException) {
            return 'rejected';
        }
    }
}
