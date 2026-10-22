<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use App\Services\DeskReservationService;
use App\Services\MonthlyReceiptService;
use App\Services\ProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class PermanentAccountDeletionConcurrencyTest extends TestCase
{
    // Each child needs committed fixtures and its own MariaDB connection.
    use CreatesDeskReservations, CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00', 'Asia/Jerusalem'));
    }

    public function test_deactivation_then_deletion_racing_worker_requests_never_resurrects_owned_data(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $this->createShiftRecord($worker, '2026-10-20');
        $this->createDeskReservation($worker, $desk, '2026-10-20');
        $otherShift = $this->createShiftRecord($otherWorker, '2026-10-25');

        $results = ConcurrentProcesses::run([
            'delete' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->deactivate($manager, $worker);
                app(AccountDeletionService::class)->delete($manager, $worker);

                return 'deleted';
            },
            'profile' => function () use ($worker): string {
                try {
                    app(ProfileService::class)->update($worker, [
                        'first_name' => 'Updated', 'last_name' => 'Worker', 'gender' => 'other', 'birth_date' => null,
                    ]);

                    return 'saved';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'receipt' => function () use ($worker): string {
                try {
                    app(MonthlyReceiptService::class)->set($worker, '2026-10', true);

                    return 'saved';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'booking' => function () use ($worker, $desk): string {
                try {
                    app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-25', '09:00', '10:00');

                    return 'saved';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
        ]);

        $this->assertSame('deleted', $results['delete']);

        foreach (['profile', 'receipt', 'booking'] as $operation) {
            $this->assertContains($results[$operation], ['saved', 'rejected']);
        }

        $this->assertDatabaseMissing('users', ['id' => $worker->id]);

        foreach (['shifts', 'desk_reservations', 'monthly_receipts'] as $table) {
            $this->assertDatabaseMissing($table, ['worker_id' => $worker->id]);
        }

        $this->assertDatabaseHas('shifts', ['id' => $otherShift->id, 'cancelled_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $otherWorker->id, 'is_active' => true]);
    }

    #[DataProvider('managerOperationOrders')]
    public function test_deletion_racing_deactivation_of_the_acting_manager_rechecks_authority(bool $deleteStartsFirst): void
    {
        $deletingManager = User::factory()->manager()->create();
        $otherManager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $operations = [
            'delete' => function () use ($deletingManager, $target): string {
                try {
                    app(AccountDeletionService::class)->delete($deletingManager, $target);

                    return 'deleted';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
            'deactivate' => function () use ($otherManager, $deletingManager): string {
                app(AccountManagementService::class)->deactivate($otherManager, $deletingManager);

                return 'deactivated';
            },
        ];

        if (! $deleteStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['delete'], ['deleted', 'rejected']);
        $this->assertFalse($deletingManager->fresh()->is_active);
        $this->assertTrue($otherManager->fresh()->is_active);
        $this->assertSame($results['delete'] === 'rejected', User::query()->whereKey($target->id)->exists());
        $this->assertSame(1, User::query()->where('role', 'manager')->where('is_active', true)->count());
    }

    public static function managerOperationOrders(): array
    {
        return ['deletion process first' => [true], 'deactivation process first' => [false]];
    }

    public function test_duplicate_deletion_requests_remove_one_account_without_foreign_key_failures(): void
    {
        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $target = User::factory()->inactive()->create();
        $this->createShiftRecord($target, '2026-10-20');
        $operations = [];

        foreach ([$firstManager, $secondManager] as $manager) {
            $operations['manager-'.$manager->id] = function () use ($manager, $target): string {
                try {
                    app(AccountDeletionService::class)->delete($manager, $target);

                    return 'deleted';
                } catch (ModelNotFoundException) {
                    // A second HTTP request would likewise receive a 404.
                    return 'already-deleted';
                }
            };
        }

        $results = ConcurrentProcesses::run($operations);

        sort($results);
        $this->assertSame(['already-deleted', 'deleted'], $results);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('users', 2);
    }
}
