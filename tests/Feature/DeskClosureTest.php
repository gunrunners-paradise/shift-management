<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use App\Services\AccountManagementService;
use App\Services\CalendarEventService;
use App\Services\DateClosureService;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class DeskClosureTest extends TestCase
{
    use CreatesDeskReservations, CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:30:00', 'Asia/Jerusalem'));
    }

    public function test_one_block_action_cancels_shifts_and_all_desk_bookings_on_that_date_with_audit_details(): void
    {
        $manager = User::factory()->manager()->create();
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $shift = $this->createShiftRecord($firstWorker, '2026-10-20', 'full');
        $completed = $this->createDeskReservation($firstWorker, $desks[0], '2026-10-20', '09:00', '10:00');
        $running = $this->createDeskReservation($secondWorker, $desks[1], '2026-10-20', '10:00', '11:00');
        $tomorrow = $this->createDeskReservation($firstWorker, $desks[0]);

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-20', 'reason' => 'המשרד סגור', 'confirm_block' => '1',
        ])->assertSessionHasNoErrors();

        // Date closure cancels every active booking on that date, even if it
        // has ended. Worker deactivation separately preserves completed work.
        foreach ([$shift, $completed, $running] as $record) {
            $record->refresh();
            $this->assertNotNull($record->cancelled_at);
            $this->assertSame($manager->id, $record->cancelled_by);
            $this->assertSame('date_blocked', $record->cancellation_reason);
            $this->assertSame('המשרד סגור', $record->cancellation_note);
        }

        $this->assertNull($tomorrow->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 3);
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_blocked_date_rejects_desk_bookings_and_unblocking_requires_a_new_booking(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $original = $this->createDeskReservation($worker, $desk);
        $closure = app(DateClosureService::class);
        $closure->block($manager, '2026-10-21', 'סגירה');
        $cancelledAt = $original->fresh()->cancelled_at;

        $this->actingAs($worker)->post(route('worker.desks.store'), [
            'desk_id' => $desk->id, 'reservation_date' => '2026-10-21', 'start_time' => '13:00', 'end_time' => '14:00',
        ])->assertSessionHasErrors('reservation_date');

        $this->travel(1)->minutes();
        $closure->block($manager, '2026-10-21', 'סגירה');
        $closure->unblock($manager, '2026-10-21');

        $this->assertTrue($cancelledAt->equalTo($original->fresh()->cancelled_at));
        $this->assertSame(0, DeskReservation::active()->count());
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-21']);
        $this->assertDatabaseMissing('blocked_dates', ['date' => '2026-10-21']);
        $replacement = app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-21', '09:00', '10:00');
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertDatabaseCount('desk_reservations', 2);
    }

    #[DataProvider('deactivationBoundaries')]
    public function test_worker_deactivation_cancels_upcoming_running_and_future_desks_and_preserves_completed_history(string $now, bool $cancelToday): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$now, 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $past = $this->createDeskReservation($worker, $desks[0], '2026-10-19');
        $today = $this->createDeskReservation($worker, $desks[0], '2026-10-20', '10:00', '11:00');
        $future = $this->createDeskReservation($worker, $desks[0], '2026-10-21');
        $other = $this->createDeskReservation($otherWorker, $desks[1], '2026-10-21');
        $shift = $this->createShiftRecord($worker, '2026-10-21', '09_13');

        app(AccountManagementService::class)->deactivate($manager, $worker);

        $this->assertFalse($worker->fresh()->is_active);
        $this->assertNull($past->fresh()->cancelled_at);
        $this->assertSame($cancelToday, $today->fresh()->cancelled_at !== null);
        $this->assertSame('worker_deactivated', $future->fresh()->cancellation_reason);
        $this->assertSame($manager->id, $future->fresh()->cancelled_by);
        $this->assertSame('worker_deactivated', $shift->fresh()->cancellation_reason);
        $this->assertNull($other->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 4);
        $this->assertDatabaseCount('users', 3);

        if ($cancelToday) {
            $this->assertSame('worker_deactivated', $today->fresh()->cancellation_reason);
        }

        $this->actingAs($worker)->get(route('worker.desks.index'))->assertRedirect(route('login'));
    }

    public static function deactivationBoundaries(): array
    {
        return [
            'upcoming reservation' => ['09:00:00', true],
            'at reservation start' => ['10:00:00', true],
            'running reservation' => ['10:30:00', true],
            'at reservation end' => ['11:00:00', false],
        ];
    }

    #[DataProvider('informationalKinds')]
    public function test_manual_events_and_holidays_do_not_close_desks(string $kind): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        app(CalendarEventService::class)->create($manager, [
            'title' => 'מידע לצוות', 'event_date' => '2026-10-21', 'kind' => $kind, 'description' => null,
        ]);

        $reservation = app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-21', '09:00', '10:00');

        $this->assertNull($reservation->cancelled_at);
        $this->assertDatabaseMissing('blocked_dates', ['date' => '2026-10-21']);
    }

    public static function informationalKinds(): array
    {
        return [['event'], ['holiday']];
    }
}
