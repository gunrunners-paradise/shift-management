<?php

namespace Tests\Feature;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use App\Services\DeskReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDeskReservations;
use Tests\TestCase;

class DeskReservationTest extends TestCase
{
    use CreatesDeskReservations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('validIntervals')]
    public function test_worker_can_reserve_without_a_shift_within_the_office_boundaries(string $start, string $end): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'start_time' => $start, 'end_time' => $end,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('desk_reservations', [
            'worker_id' => $worker->id,
            'desk_id' => $desk->id,
            'reservation_date' => '2026-10-21',
            'start_time' => $start.':00',
            'end_time' => $end.':00',
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseCount('shifts', 0);
    }

    public static function validIntervals(): array
    {
        return [
            'first hour' => ['09:00', '10:00'],
            'last hour ending at closing' => ['15:30', '16:30'],
            'half hour aligned endpoints' => ['09:30', '11:00'],
            'whole office day' => ['09:00', '16:30'],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_invalid_or_too_short_intervals_are_rejected_by_the_server(string $start, string $end, string $errorField): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $response = $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'start_time' => $start, 'end_time' => $end,
        ]));

        $response->assertSessionHasErrors($errorField);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    public static function invalidIntervals(): array
    {
        return [
            'only thirty minutes' => ['09:00', '09:30', 'end_time'],
            'starts before office opens' => ['08:30', '10:00', 'end_time'],
            'ends after office closes' => ['16:00', '17:00', 'end_time'],
            'quarter hour start' => ['09:15', '10:30', 'start_time'],
            'quarter hour end' => ['09:00', '10:15', 'start_time'],
            'end equals start' => ['10:00', '10:00', 'end_time'],
            'end precedes start' => ['11:00', '10:00', 'end_time'],
            'overnight' => ['23:00', '01:00', 'end_time'],
            'missing start' => ['', '11:00', 'start_time'],
            'malformed start' => ['not-a-time', '11:00', 'start_time'],
        ];
    }

    #[DataProvider('unavailableDates')]
    public function test_past_weekend_out_of_horizon_and_invalid_dates_cannot_be_booked(string $date): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'reservation_date' => $date,
        ]))->assertSessionHasErrors('reservation_date');

        $this->assertDatabaseCount('desk_reservations', 0);
    }

    public static function unavailableDates(): array
    {
        return [
            'past weekday' => ['2026-10-19'],
            'Friday' => ['2026-10-23'],
            'Saturday' => ['2026-10-24'],
            'day twenty nine' => ['2026-11-18'],
            'impossible date' => ['2026-02-30'],
            'timestamp instead of date' => ['2026-10-21T09:00:00+03:00'],
        ];
    }

    public function test_the_twenty_eighth_calendar_day_is_inclusive_across_a_daylight_saving_change(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'reservation_date' => '2026-11-17',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('desk_reservations', ['reservation_date' => '2026-11-17', 'cancelled_at' => null]);
    }

    #[DataProvider('todayStartBoundaries')]
    public function test_todays_new_booking_must_start_strictly_after_the_current_time(string $now, bool $allowed): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$now, 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $response = $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'reservation_date' => '2026-10-20', 'start_time' => '10:00', 'end_time' => '11:00',
        ]));

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseCount('desk_reservations', 1);
        } else {
            $response->assertSessionHasErrors('start_time');
            $this->assertDatabaseCount('desk_reservations', 0);
        }
    }

    public static function todayStartBoundaries(): array
    {
        return [
            'one second before start' => ['09:59:59', true],
            'exactly at start' => ['10:00:00', false],
            'one second after start' => ['10:00:01', false],
        ];
    }

    public function test_local_israeli_today_is_used_when_utc_still_has_the_previous_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 21:30:00', 'UTC')->setTimezone('Asia/Jerusalem'));
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'reservation_date' => '2026-10-20',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2026-10-20', DeskReservation::active()->sole()->reservation_date->toDateString());
    }

    public function test_disjoint_second_booking_on_another_desk_is_rejected_for_the_same_worker_date(): void
    {
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $original = app(DeskReservationService::class)->book($worker, $desks[0]->id, '2026-10-21', '09:00', '10:00');

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desks[1], [
            'start_time' => '14:00', 'end_time' => '15:00',
        ]))->assertSessionHasErrors();

        $this->assertSame($original->id, DeskReservation::active()->sole()->id);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    #[DataProvider('overlappingIntervals')]
    public function test_any_overlap_on_the_same_desk_and_date_is_rejected(string $start, string $end): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $this->createDeskReservation($otherWorker, $desk, '2026-10-21', '10:00', '12:00');

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'start_time' => $start, 'end_time' => $end,
        ]))->assertSessionHasErrors();

        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public static function overlappingIntervals(): array
    {
        return [
            'same interval' => ['10:00', '12:00'],
            'crosses start' => ['09:30', '10:30'],
            'crosses end' => ['11:30', '12:30'],
            'inside existing' => ['10:30', '11:30'],
            'contains existing' => ['09:00', '13:00'],
        ];
    }

    public function test_back_to_back_workers_and_a_different_desk_may_use_the_same_day(): void
    {
        $desks = Desk::query()->take(2)->get();
        $service = app(DeskReservationService::class);
        $service->book(User::factory()->create(), $desks[0]->id, '2026-10-21', '09:00', '10:00');
        $service->book(User::factory()->create(), $desks[0]->id, '2026-10-21', '10:00', '11:00');
        $service->book(User::factory()->create(), $desks[1]->id, '2026-10-21', '09:00', '10:00');

        $this->assertSame(3, DeskReservation::active()->count());
    }

    public function test_editing_replaces_the_active_row_and_cancelling_releases_the_worker_date_slot(): void
    {
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $service = app(DeskReservationService::class);
        $original = $service->book($worker, $desks[0]->id, '2026-10-21', '09:00', '10:00');
        $unchanged = $service->update($worker, $original, $desks[0]->id, '09:00', '10:00');
        $this->assertSame($original->id, $unchanged->id);

        $this->actingAs($worker)->patch(route('worker.desks.update', $original), [
            'reservation_date' => '2026-10-21', 'desk_id' => $desks[1]->id, 'start_time' => '13:00', 'end_time' => '14:00',
        ])->assertSessionHasNoErrors();

        $replacement = DeskReservation::active()->sole();
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame('09:00', $original->fresh()->start_time);
        $this->assertSame('replaced', $original->fresh()->cancellation_reason);
        $this->assertSame($worker->id, $original->fresh()->cancelled_by);
        $this->assertSame($desks[1]->id, $replacement->desk_id);

        $this->delete(route('worker.desks.destroy', $replacement), ['confirm_cancellation' => '1'])
            ->assertSessionHasNoErrors();
        $cancelledAt = $replacement->fresh()->cancelled_at;
        $this->travel(1)->minutes();
        $this->delete(route('worker.desks.destroy', $replacement), ['confirm_cancellation' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($cancelledAt->equalTo($replacement->fresh()->cancelled_at));
        $this->assertSame('worker_cancelled', $replacement->fresh()->cancellation_reason);
        $newReservation = $service->book($worker, $desks[0]->id, '2026-10-21', '09:00', '10:00');
        $this->assertSame($newReservation->id, DeskReservation::active()->sole()->id);
        $this->assertDatabaseCount('desk_reservations', 3);
    }

    public function test_an_edit_that_collides_with_another_worker_keeps_the_original_booking(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $original = $this->createDeskReservation($worker, $desks[0]);
        $this->createDeskReservation($otherWorker, $desks[1], '2026-10-21', '13:00', '14:00');

        $this->actingAs($worker)->patch(route('worker.desks.update', $original), [
            'reservation_date' => '2026-10-21', 'desk_id' => $desks[1]->id, 'start_time' => '13:00', 'end_time' => '14:00',
        ])->assertSessionHasErrors();

        $this->assertNull($original->fresh()->cancelled_at);
        $this->assertSame($desks[0]->id, $original->fresh()->desk_id);
        $this->assertDatabaseCount('desk_reservations', 2);
    }

    #[DataProvider('mutationStartBoundaries')]
    public function test_worker_can_edit_or_cancel_only_strictly_before_the_original_start(string $now, bool $allowed): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$now, 'Asia/Jerusalem'));
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $toEdit = $this->createDeskReservation($firstWorker, $desks[0], '2026-10-20', '10:00', '11:00');
        $toCancel = $this->createDeskReservation($secondWorker, $desks[1], '2026-10-20', '10:00', '11:00');

        $editResponse = $this->actingAs($firstWorker)->patch(route('worker.desks.update', $toEdit), [
            'reservation_date' => '2026-10-20', 'desk_id' => $desks[0]->id, 'start_time' => '12:00', 'end_time' => '13:00',
        ]);

        if ($allowed) {
            $editResponse->assertSessionHasNoErrors();
        } else {
            $editResponse->assertSessionHasErrors();
        }

        $this->flushSession();
        $cancelResponse = $this->actingAs($secondWorker)->delete(route('worker.desks.destroy', $toCancel), ['confirm_cancellation' => '1']);

        if ($allowed) {
            $cancelResponse->assertSessionHasNoErrors();
        } else {
            $cancelResponse->assertSessionHasErrors();
        }

        $this->assertSame($allowed, $toEdit->fresh()->cancelled_at !== null);
        $this->assertSame($allowed, $toCancel->fresh()->cancelled_at !== null);
    }

    public static function mutationStartBoundaries(): array
    {
        return [
            'one second before original start' => ['09:59:59', true],
            'exactly original start' => ['10:00:00', false],
            'already running' => ['10:30:00', false],
            'already completed' => ['11:00:00', false],
        ];
    }

    public function test_booking_rechecks_the_clock_after_waiting_for_the_date_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:59:59', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $advancedClock = false;

        DB::listen(function (QueryExecuted $query) use (&$advancedClock): void {
            if ($advancedClock || ! str_contains(strtolower($query->sql), 'calendar_dates') || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            $advancedClock = true;
            $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jerusalem'));
        });

        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [
            'reservation_date' => '2026-10-20', 'start_time' => '10:00', 'end_time' => '11:00',
        ]))->assertSessionHasErrors('start_time');

        $this->assertTrue($advancedClock);
        $this->assertDatabaseCount('desk_reservations', 0);
    }

    #[DataProvider('reservationMutations')]
    public function test_edit_and_cancel_recheck_the_original_start_after_waiting_for_the_date_lock(string $mutation): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:59:59', 'Asia/Jerusalem'));
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk, '2026-10-20', '10:00', '11:00');
        $advancedClock = false;

        DB::listen(function (QueryExecuted $query) use (&$advancedClock): void {
            $sql = strtolower($query->sql);

            if ($advancedClock || ! str_contains($sql, 'calendar_dates') || ! str_contains($sql, 'for update')) {
                return;
            }

            // A request valid before its lock wait must not change a booking
            // that began while another transaction held the date key.
            $advancedClock = true;
            $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Asia/Jerusalem'));
        });

        $this->actingAs($worker);

        if ($mutation === 'edit') {
            $this->patch(route('worker.desks.update', $reservation), [
                'reservation_date' => '2026-10-20', 'desk_id' => $desk->id,
                'start_time' => '12:00', 'end_time' => '13:00',
            ])->assertSessionHasErrors('reservation');
        } else {
            $this->delete(route('worker.desks.destroy', $reservation), ['confirm_cancellation' => '1'])
                ->assertSessionHasErrors('reservation');
        }

        $this->assertTrue($advancedClock);
        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public static function reservationMutations(): array
    {
        return ['edit' => ['edit'], 'cancel' => ['cancel']];
    }

    public function test_cancellation_requires_explicit_confirmation(): void
    {
        $worker = User::factory()->create();
        $reservation = $this->createDeskReservation($worker, Desk::query()->firstOrFail());

        $this->actingAs($worker)->delete(route('worker.desks.destroy', $reservation))
            ->assertSessionHasErrors('confirm_cancellation');

        $this->assertNull($reservation->fresh()->cancelled_at);
    }

    #[DataProvider('ownershipFields')]
    public function test_forged_worker_ownership_is_rejected_for_booking_edit_and_cancellation(string $field): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);
        $this->actingAs($worker)->post(route('worker.desks.store'), $this->bookingInput($desk, [$field => $otherWorker->id]))
            ->assertSessionHasErrors($field);
        $this->patch(route('worker.desks.update', $reservation), [
            'reservation_date' => '2026-10-21', 'desk_id' => $desk->id, 'start_time' => '12:00', 'end_time' => '13:00', $field => $otherWorker->id,
        ])->assertSessionHasErrors($field);
        $this->delete(route('worker.desks.destroy', $reservation), [
            'confirm_cancellation' => '1', $field => $otherWorker->id,
        ])->assertSessionHasErrors($field);

        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public static function ownershipFields(): array
    {
        return [['worker_id'], ['user_id']];
    }

    public function test_worker_cannot_modify_another_workers_reservation_by_guessing_its_id(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($otherWorker, $desk);

        $this->actingAs($worker)->patch(route('worker.desks.update', $reservation), [
            'reservation_date' => '2026-10-21', 'desk_id' => $desk->id, 'start_time' => '12:00', 'end_time' => '13:00',
        ])->assertForbidden();
        $this->delete(route('worker.desks.destroy', $reservation), ['confirm_cancellation' => '1'])->assertForbidden();

        $this->assertNull($reservation->fresh()->cancelled_at);
    }

    public function test_reservation_date_cannot_be_changed_through_the_edit_endpoint(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);

        $this->actingAs($worker)->patch(route('worker.desks.update', $reservation), [
            'desk_id' => $desk->id, 'start_time' => '12:00', 'end_time' => '13:00',
            'reservation_date' => '2026-10-22',
        ])->assertSessionHasErrors('reservation_date');

        $this->assertSame('2026-10-21', $reservation->fresh()->reservation_date->toDateString());
        $this->assertNull($reservation->fresh()->cancelled_at);
    }

    public function test_managers_and_guests_cannot_use_worker_desk_endpoints(): void
    {
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $desk = Desk::query()->firstOrFail();
        $reservation = $this->createDeskReservation($worker, $desk);

        $this->get(route('worker.desks.index'))->assertRedirect(route('login'));
        $this->post(route('worker.desks.store'), $this->bookingInput($desk))->assertRedirect(route('login'));
        $this->patch(route('worker.desks.update', $reservation), [])->assertRedirect(route('login'));
        $this->delete(route('worker.desks.destroy', $reservation), [])->assertRedirect(route('login'));

        $this->actingAs($manager)->get(route('worker.desks.index'))->assertForbidden();
        $this->post(route('worker.desks.store'), $this->bookingInput($desk))->assertForbidden();
        $this->patch(route('worker.desks.update', $reservation), [])->assertForbidden();
        $this->delete(route('worker.desks.destroy', $reservation), [])->assertForbidden();

        $this->assertNull($reservation->fresh()->cancelled_at);
        $this->assertDatabaseCount('desk_reservations', 1);
    }

    public function test_service_rechecks_an_inactive_worker_instead_of_trusting_a_stale_model(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        User::query()->whereKey($worker->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(DeskReservationService::class)->book($worker, $desk->id, '2026-10-21', '09:00', '10:00');
    }

    public function test_database_rejects_a_second_active_booking_for_the_same_worker_and_date(): void
    {
        $worker = User::factory()->create();
        $desks = Desk::query()->take(2)->get();
        $this->createDeskReservation($worker, $desks[0]);

        $this->expectException(QueryException::class);

        $this->createDeskReservation($worker, $desks[1], '2026-10-21', '13:00', '14:00');
    }

    public function test_database_allows_multiple_cancelled_bookings_and_one_active_booking_for_a_worker_date(): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();
        $cancelled = ['cancelled_at' => now(), 'cancelled_by' => $worker->id, 'cancellation_reason' => 'worker_cancelled'];
        $this->createDeskReservation($worker, $desk, overrides: $cancelled);
        $this->createDeskReservation($worker, $desk, overrides: $cancelled);
        $active = $this->createDeskReservation($worker, $desk);

        $this->assertSame($active->id, DeskReservation::active()->sole()->id);
        $this->assertDatabaseCount('desk_reservations', 3);
    }

    #[DataProvider('invalidStoredIntervals')]
    public function test_database_also_rejects_intervals_outside_the_approved_office_bounds(string $start, string $end): void
    {
        $worker = User::factory()->create();
        $desk = Desk::query()->firstOrFail();

        $this->expectException(QueryException::class);

        $this->createDeskReservation($worker, $desk, '2026-10-21', $start, $end);
    }

    public static function invalidStoredIntervals(): array
    {
        return [
            'less than an hour' => ['09:00', '09:30'],
            'outside opening hours' => ['08:30', '10:00'],
            'outside closing hours' => ['16:00', '17:00'],
            'off half hour grid' => ['09:15', '10:15'],
            'nonzero seconds' => ['09:00:01', '10:30:00'],
        ];
    }

    private function bookingInput(Desk $desk, array $overrides = []): array
    {
        return array_replace([
            'desk_id' => $desk->id,
            'reservation_date' => '2026-10-21',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ], $overrides);
    }
}
