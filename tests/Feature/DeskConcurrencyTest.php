<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\Shift;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\DateClosureService;
use App\Services\DeskManagementService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\Support\ConcurrentProcesses;
use Tests\TestCase;

class DeskConcurrencyTest extends TestCase
{
    use CreatesDeskReservations, CreatesShiftRecords, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The MariaDB races require PHP pcntl and local socket pairs.');
        }

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Jerusalem'));
    }

    public function test_five_workers_racing_for_one_desk_interval_leave_exactly_one_booking(): void
    {
        $desk = Desk::query()->firstOrFail();
        $operations = [];

        foreach (User::factory()->count(5)->create() as $worker) {
            $operations['worker-'.$worker->id] = function () use ($worker, $desk): string {
                return $this->attemptBooking($worker, $desk, '2026-10-21', '09:00', '11:00');
            };
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === 'booked')));
        $this->assertSame(4, count(array_filter($results, fn (string $result): bool => $result === 'rejected')));
        $this->assertSame(1, DeskReservation::active()->count());
        $this->assertDatabaseCount('desk_reservations', 1);
        $this->assertDatabaseCount('calendar_dates', 1);
    }

    public function test_one_worker_racing_to_book_two_desks_gets_only_one_active_daily_reservation(): void
    {
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();

        $results = ConcurrentProcesses::run([
            'morning' => fn (): string => $this->attemptBooking($worker, $desks[0], '2026-10-21', '09:00', '10:00'),
            'afternoon' => fn (): string => $this->attemptBooking($worker, $desks[1], '2026-10-21', '13:00', '14:00'),
        ]);

        $this->assertContains('booked', $results);
        $this->assertContains('rejected', $results);
        $this->assertSame(1, DeskReservation::active()->where('worker_id', $worker->id)->count());
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public function test_adjacent_intervals_for_different_workers_both_succeed_concurrently(): void
    {
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $results = ConcurrentProcesses::run([
            'first' => fn (): string => $this->attemptBooking($firstWorker, $desk, '2026-10-21', '09:00', '10:00'),
            'second' => fn (): string => $this->attemptBooking($secondWorker, $desk, '2026-10-21', '10:00', '11:00'),
        ]);

        $this->assertSame(['first' => 'booked', 'second' => 'booked'], $results);
        $this->assertSame(2, DeskReservation::active()->count());
    }

    #[DataProvider('firstDateRaceOrders')]
    public function test_blocking_a_first_ever_date_racing_a_desk_booking_never_leaves_an_active_booking(string $date, bool $bookingStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $this->assertDatabaseMissing('calendar_dates', ['date' => $date]);
        $this->assertDatabaseMissing('blocked_dates', ['date' => $date]);
        $operations = [
            'block' => function () use ($manager, $date): string {
                app(DateClosureService::class)->block($manager, $date, 'סגירה');

                return 'blocked';
            },
            'book' => fn (): string => $this->attemptBooking($worker, $desk, $date, '09:00', '10:00'),
        ];

        if ($bookingStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('blocked', $results['block']);
        $this->assertContains($results['book'], ['booked', 'rejected']);
        $this->assertDatabaseHas('blocked_dates', ['date' => $date]);
        $this->assertSame(0, DeskReservation::active()->where('reservation_date', $date)->count());
        $this->assertCancelledIfBooked($results['book'], $manager, 'date_blocked');
    }

    public static function firstDateRaceOrders(): array
    {
        return [
            'block first Wednesday' => ['2026-10-21', false],
            'book first Thursday' => ['2026-10-22', true],
            'book first Sunday' => ['2026-10-25', true],
        ];
    }

    #[DataProvider('deskDeactivationOrders')]
    public function test_desk_deactivation_racing_its_first_booking_leaves_no_future_active_reservation(bool $bookingStartsFirst): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = app(DeskManagementService::class)->create($manager, 'עמדה חדשה לבדיקה');
        $this->assertDatabaseMissing('calendar_dates', ['date' => '2026-10-21']);
        $this->assertDatabaseMissing('desk_reservations', ['desk_id' => $desk->id]);
        $operations = [
            'deactivate' => function () use ($manager, $desk): string {
                app(DeskManagementService::class)->deactivate($manager, $desk);

                return 'deactivated';
            },
            'book' => fn (): string => $this->attemptBooking($worker, $desk, '2026-10-21', '09:00', '10:00'),
        ];

        if ($bookingStartsFirst) {
            $operations = array_reverse($operations, true);
        }

        $results = ConcurrentProcesses::run($operations);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['book'], ['booked', 'rejected']);
        $this->assertFalse($desk->fresh()->is_active);
        $this->assertSame(0, DeskReservation::active()->count());
        $this->assertCancelledIfBooked($results['book'], $manager, 'desk_deactivated');
    }

    public static function deskDeactivationOrders(): array
    {
        return ['deactivate first' => [false], 'book first' => [true]];
    }

    public function test_worker_deactivation_racing_a_booking_cancels_or_rejects_it_without_erasing_history(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $results = ConcurrentProcesses::run([
            'book' => fn (): string => $this->attemptBooking($worker, $desk, '2026-10-21', '09:00', '10:00'),
            'deactivate' => function () use ($manager, $worker): string {
                app(AccountManagementService::class)->deactivate($manager, $worker);

                return 'deactivated';
            },
        ]);

        $this->assertSame('deactivated', $results['deactivate']);
        $this->assertContains($results['book'], ['booked', 'rejected']);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertSame(0, DeskReservation::active()->count());
        $this->assertCancelledIfBooked($results['book'], $manager, 'worker_deactivated');
    }

    public function test_editing_into_a_desk_racing_a_new_booking_cannot_double_book_the_interval(): void
    {
        $editingWorker = User::factory()->create();
        $bookingWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $original = $this->createDeskReservation($editingWorker, $desks[0]);

        $results = ConcurrentProcesses::run([
            'edit' => function () use ($editingWorker, $original, $desks): string {
                try {
                    app(DeskReservationService::class)->update($editingWorker, $original, $desks[1]->id, '13:00', '14:00');

                    return 'booked';
                } catch (ValidationException) {
                    return 'rejected';
                }
            },
            'new' => fn (): string => $this->attemptBooking($bookingWorker, $desks[1], '2026-10-21', '13:00', '14:00'),
        ]);

        $this->assertContains('booked', $results);
        $this->assertContains('rejected', $results);
        $this->assertSame(1, DeskReservation::active()->where('desk_id', $desks[1]->id)->count());
        $this->assertSame(1, DeskReservation::active()->where('worker_id', $editingWorker->id)->count());
        $this->assertSame($results['edit'] === 'booked', $original->fresh()->cancelled_at !== null);
    }

    public function test_blocking_worker_deactivation_and_desk_deactivation_finish_without_lock_order_deadlocks(): void
    {
        $firstManager = User::factory()->manager()->create();
        $secondManager = User::factory()->manager()->create();
        $thirdManager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $this->createDeskReservation($worker, $desks[0], '2026-10-21');
        $this->createDeskReservation($worker, $desks[1], '2026-10-22');
        $this->createDeskReservation($otherWorker, $desks[0], '2026-10-21', '13:00', '14:00');
        $this->createShiftRecord($worker, '2026-10-21');
        $this->createShiftRecord($worker, '2026-10-22');

        $results = ConcurrentProcesses::run([
            'block' => function () use ($firstManager): string {
                app(DateClosureService::class)->block($firstManager, '2026-10-21', 'סגירה');

                return 'blocked';
            },
            'worker' => function () use ($secondManager, $worker): string {
                app(AccountManagementService::class)->deactivate($secondManager, $worker);

                return 'deactivated';
            },
            'desk' => function () use ($thirdManager, $desks): string {
                app(DeskManagementService::class)->deactivate($thirdManager, $desks[1]);

                return 'deactivated';
            },
        ]);

        $this->assertSame(['block' => 'blocked', 'worker' => 'deactivated', 'desk' => 'deactivated'], $results);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertFalse($desks[1]->fresh()->is_active);
        $this->assertSame(0, DeskReservation::active()->count());
        $this->assertSame(0, Shift::active()->count());
        $this->assertDatabaseCount('desk_reservations', 3);
        $this->assertDatabaseCount('shifts', 2);
    }

    /** Only expected business rejections are caught; SQL errors fail the race. */
    private function attemptBooking(User $worker, Desk $desk, string $date, string $start, string $end): string
    {
        try {
            app(DeskReservationService::class)->book($worker, $desk->id, $date, $start, $end);

            return 'booked';
        } catch (ValidationException|AuthorizationException) {
            return 'rejected';
        }
    }

    private function assertCancelledIfBooked(string $bookingResult, User $manager, string $reason): void
    {
        if ($bookingResult === 'booked') {
            $cancelled = DeskReservation::query()->sole();
            $this->assertNotNull($cancelled->cancelled_at);
            $this->assertSame($manager->id, $cancelled->cancelled_by);
            $this->assertSame($reason, $cancelled->cancellation_reason);
        } else {
            $this->assertDatabaseCount('desk_reservations', 0);
        }
    }
}
