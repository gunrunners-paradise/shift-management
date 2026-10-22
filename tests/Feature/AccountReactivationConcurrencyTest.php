<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class AccountReactivationConcurrencyTest extends TestCase
{
    use CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('reactivationOrders')]
    public function test_reactivation_racing_permanent_deletion_cannot_resurrect_an_account_or_delete_an_active_one(bool $reactivationStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $history = $this->createShiftRecord($target, '2026-10-20', '09_13', [
            'cancelled_at' => now(), 'cancelled_by' => $manager->id, 'cancellation_reason' => 'worker_deactivated',
        ]);
        $operations = [
            'reactivate' => function () use ($manager, $target): string {
                try {
                    app(AccountManagementService::class)->reactivate($manager, $target);

                    return 'reactivated';
                } catch (ModelNotFoundException) {
                    return 'already-deleted';
                }
            },
            'delete' => function () use ($otherManager, $target): string {
                try {
                    app(AccountDeletionService::class)->delete($otherManager, $target);

                    return 'deleted';
                } catch (ValidationException) {
                    return 'rejected-active-account';
                }
            },
        ];

        if (! $reactivationStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        // The shared manager gate permits exactly these two complete outcomes.
        if ($results['reactivate'] === 'reactivated') {
            $this->assertSame('rejected-active-account', $results['delete']);
            $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true, 'session_version' => 1]);
            $this->assertDatabaseHas('shifts', ['id' => $history->id, 'cancellation_reason' => 'worker_deactivated']);
            $this->assertNotNull($history->fresh()->cancelled_at);
        } else {
            $this->assertSame('already-deleted', $results['reactivate']);
            $this->assertSame('deleted', $results['delete']);
            $this->assertDatabaseMissing('users', ['id' => $target->id]);
            $this->assertDatabaseMissing('shifts', ['worker_id' => $target->id]);
        }
    }

    #[DataProvider('reactivationOrders')]
    public function test_reactivation_racing_deactivation_of_the_actor_rechecks_manager_authority(bool $reactivationStartsFirst): void
    {
        $actingManager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $operations = [
            'reactivate' => function () use ($actingManager, $target): string {
                try {
                    app(AccountManagementService::class)->reactivate($actingManager, $target);

                    return 'reactivated';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'deactivate' => function () use ($otherManager, $actingManager): string {
                app(AccountManagementService::class)->deactivate($otherManager, $actingManager);

                return 'deactivated';
            },
        ];

        if (! $reactivationStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['reactivate'], ['reactivated', 'rejected']);
        $this->assertFalse($actingManager->fresh()->is_active);
        $this->assertTrue($otherManager->fresh()->is_active);
        $this->assertSame($results['reactivate'] === 'reactivated', $target->fresh()->is_active);
        $this->assertSame($results['reactivate'] === 'reactivated' ? 1 : 0, $target->fresh()->session_version);
        $this->assertDatabaseCount('users', 3);
    }

    public static function reactivationOrders(): array
    {
        return ['reactivation process first' => [true], 'other manager process first' => [false]];
    }
}
