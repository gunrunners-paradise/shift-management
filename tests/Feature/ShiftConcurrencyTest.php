<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\DateClosureService;
use App\Services\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class ShiftConcurrencyTest extends TestCase
{
    use CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('freshDates')]
    public function test_blocking_a_first_ever_date_racing_a_selection_never_leaves_an_active_shift(string $date, bool $selectionStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $this->assertDatabaseMissing('calendar_dates', ['date' => $date]);
        $this->assertDatabaseMissing('blocked_dates', ['date' => $date]);

        $operations = [
            'block' => function () use ($manager, $date): string {
                app(DateClosureService::class)->block($manager, $date, 'סגירה');

                return 'blocked';
            },
            'select' => function () use ($worker, $date): string {
                try {
                    app(ShiftSchedulingService::class)->select($worker, $date, 'full');

                    return 'selected';
                } catch (ValidationException) {
                    return 'rejected';
                }
            },
        ];

        if ($selectionStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('blocked', $results['block']);
        $this->assertContains($results['select'], ['selected', 'rejected']);
        $this->assertDatabaseHas('blocked_dates', ['date' => $date]);
        $this->assertSame(0, Shift::active()->where('shift_date', $date)->count());

        if ($results['select'] === 'selected') {
            $cancelled = Shift::query()->sole();
            $this->assertNotNull($cancelled->cancelled_at);
            $this->assertSame($manager->id, $cancelled->cancelled_by);
            $this->assertSame('date_blocked', $cancelled->cancellation_reason);
        } else {
            $this->assertDatabaseCount('shifts', 0);
        }
    }

    public static function freshDates(): array
    {
        return [
            'Wednesday block starts first' => ['2026-10-21', false],
            'Thursday selection starts first' => ['2026-10-22', true],
            'Sunday selection starts first' => ['2026-10-25', true],
        ];
    }

    public function test_two_simultaneous_period_choices_for_one_worker_are_serialized_with_history(): void
    {
        $worker = User::factory()->create();

        $results = ConcurrentProcesses::run([
            'four-hour' => function () use ($worker): string {
                app(ShiftSchedulingService::class)->select($worker, '2026-10-21', '09_13');

                return 'selected';
            },
            'full-day' => function () use ($worker): string {
                app(ShiftSchedulingService::class)->select($worker, '2026-10-21', 'full');

                return 'selected';
            },
        ]);

        $this->assertSame(['four-hour' => 'selected', 'full-day' => 'selected'], $results);
        $this->assertSame(1, Shift::active()->count());
        $this->assertDatabaseCount('shifts', 2);
        $cancelled = Shift::query()->whereNotNull('cancelled_at')->sole();
        $this->assertSame($worker->id, $cancelled->cancelled_by);
        $this->assertSame('replaced', $cancelled->cancellation_reason);
        $this->assertNotSame($cancelled->period, Shift::active()->sole()->period);
    }

    public function test_two_workers_can_concurrently_choose_the_same_period_on_a_first_ever_date(): void
    {
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $this->assertDatabaseMissing('calendar_dates', ['date' => '2026-10-21']);

        $results = ConcurrentProcesses::run([
            'first-worker' => function () use ($firstWorker): string {
                app(ShiftSchedulingService::class)->select($firstWorker, '2026-10-21', '09_13');

                return 'selected';
            },
            'second-worker' => function () use ($secondWorker): string {
                app(ShiftSchedulingService::class)->select($secondWorker, '2026-10-21', '09_13');

                return 'selected';
            },
        ]);

        $this->assertSame(['first-worker' => 'selected', 'second-worker' => 'selected'], $results);
        $this->assertSame(2, Shift::active()->where('shift_date', '2026-10-21')->where('period', '09_13')->count());
        $this->assertDatabaseCount('calendar_dates', 1);
        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_deactivation_racing_a_selection_leaves_the_inactive_worker_without_a_future_shift(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();

        $results = ConcurrentProcesses::run([
            'deactivate' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->deactivate($manager, $worker);

                return 'deactivated';
            },
            'select' => function () use ($worker): string {
                try {
                    app(ShiftSchedulingService::class)->select($worker, '2026-10-21', 'full');

                    return 'selected';
                } catch (AuthorizationException) {
                    return 'rejected';
                }
            },
        ]);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['select'], ['selected', 'rejected']);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertSame(0, Shift::active()->count());

        if ($results['select'] === 'selected') {
            $cancelled = Shift::query()->sole();
            $this->assertSame('worker_deactivated', $cancelled->cancellation_reason);
            $this->assertSame($manager->id, $cancelled->cancelled_by);
        } else {
            $this->assertDatabaseCount('shifts', 0);
        }
    }

    public function test_blocking_and_worker_deactivation_finish_without_lock_order_deadlocks(): void
    {
        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-21', 'full');
        $this->createShiftRecord($worker, '2026-10-22', '09_13');
        $this->createShiftRecord($otherWorker, '2026-10-21', '11_15');

        $results = ConcurrentProcesses::run([
            'block' => function () use ($firstManager): string {
                app(DateClosureService::class)->block($firstManager, '2026-10-21', null);

                return 'blocked';
            },
            'deactivate' => function () use ($secondManager, $worker): string {
                app(AccountManagementService::class)->deactivate($secondManager, $worker);

                return 'deactivated';
            },
        ]);

        $this->assertSame(['block' => 'blocked', 'deactivate' => 'deactivated'], $results);
        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-10-21']);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertSame(0, Shift::active()->count());
        $this->assertDatabaseCount('shifts', 3);
    }
}
