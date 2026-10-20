<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class ShiftSchedulingTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:00:00', 'Asia/Jerusalem'));
    }

    #[DataProvider('approvedPeriods')]
    public function test_worker_can_choose_each_of_the_five_approved_periods(string $period): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => $period,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('shifts', [
            'worker_id' => $worker->id,
            'shift_date' => '2026-10-18',
            'period' => $period,
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseCount('shifts', 1);
    }

    public static function approvedPeriods(): array
    {
        return [
            '09:00–13:00' => ['09_13'],
            '10:00–14:00' => ['10_14'],
            '11:00–15:00' => ['11_15'],
            '12:00–16:00' => ['12_16'],
            'full day' => ['full'],
        ];
    }

    #[DataProvider('unapprovedPeriods')]
    public function test_obsolete_or_unapproved_periods_are_rejected(mixed $period): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => $period,
        ])->assertSessionHasErrors('period');

        $this->assertDatabaseCount('shifts', 0);
    }

    public static function unapprovedPeriods(): array
    {
        return [
            'legacy morning' => ['morning'],
            'legacy afternoon' => ['afternoon'],
            'work from home' => ['home'],
            'none uses the cancellation endpoint' => ['none'],
            'unknown interval' => ['09_14'],
            'missing period' => [null],
            'array instead of code' => [['full']],
        ];
    }

    public function test_replacing_clearing_and_rebooking_keep_the_original_history(): void
    {
        $worker = User::factory()->create();
        $service = app(ShiftSchedulingService::class);
        $first = $service->select($worker, '2026-10-18', '09_13');

        $repeated = $service->select($worker, '2026-10-18', '09_13');
        $this->assertSame($first->id, $repeated->id);
        $this->assertDatabaseCount('shifts', 1);

        $replacement = $service->select($worker, '2026-10-18', '12_16');

        $this->assertSame('09_13', $first->fresh()->period);
        $this->assertNotNull($first->fresh()->cancelled_at);
        $this->assertSame($worker->id, $first->fresh()->cancelled_by);
        $this->assertSame('replaced', $first->fresh()->cancellation_reason);
        $this->assertSame($replacement->id, Shift::active()->sole()->id);

        $service->clear($worker, '2026-10-18');
        $cancelledAt = $replacement->fresh()->cancelled_at;
        $this->assertNotNull($cancelledAt);
        $this->assertSame($worker->id, $replacement->fresh()->cancelled_by);
        $this->assertSame('worker_cancelled', $replacement->fresh()->cancellation_reason);

        $this->travel(1)->minutes();
        $service->clear($worker, '2026-10-18');

        $this->assertTrue($cancelledAt->equalTo($replacement->fresh()->cancelled_at));
        $this->assertSame(0, Shift::active()->count());
        $this->assertDatabaseCount('shifts', 2);

        $newSelection = $service->select($worker, '2026-10-18', 'full');

        $this->assertNotSame($replacement->id, $newSelection->id);
        $this->assertSame($newSelection->id, Shift::active()->sole()->id);
        $this->assertDatabaseCount('shifts', 3);
    }

    public function test_worker_can_replace_and_clear_todays_shift_after_every_period_has_ended(): void
    {
        $worker = User::factory()->create();
        $original = $this->createShiftRecord($worker, '2026-10-18');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 23:59:59', 'Asia/Jerusalem'));

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => 'full',
        ])->assertSessionHasNoErrors();
        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-18']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($original->fresh()->cancelled_at);
        $this->assertSame(0, Shift::active()->count());
        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_day_28_is_inclusive_even_when_daylight_saving_changes_inside_the_window(): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-11-15',
            'period' => 'full',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', ['shift_date' => '2026-11-15', 'cancelled_at' => null]);
    }

    #[DataProvider('unavailableDates')]
    public function test_worker_cannot_select_an_unavailable_or_invalid_date(string $date): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => $date,
            'period' => '09_13',
        ])->assertSessionHasErrors('shift_date');

        $this->assertDatabaseCount('shifts', 0);
    }

    public static function unavailableDates(): array
    {
        return [
            'past weekday' => ['2026-10-15'],
            'Friday' => ['2026-10-23'],
            'Saturday' => ['2026-10-24'],
            'day 29' => ['2026-11-16'],
            'impossible date' => ['2026-02-30'],
            'timestamp instead of calendar date' => ['2026-10-18T10:00:00+03:00'],
        ];
    }

    #[DataProvider('uneditableExistingDates')]
    public function test_worker_cannot_clear_a_shift_outside_the_editable_window(string $date): void
    {
        $worker = User::factory()->create();
        $existing = $this->createShiftRecord($worker, $date);

        $this->actingAs($worker)->delete(route('worker.shifts.destroy', ['date' => $date]))
            ->assertSessionHasErrors('shift_date');

        $this->assertNull($existing->fresh()->cancelled_at);
    }

    public static function uneditableExistingDates(): array
    {
        return [
            'past weekday' => ['2026-10-15'],
            'day 29' => ['2026-11-16'],
        ];
    }

    public function test_israeli_calendar_today_is_used_when_utc_still_has_the_previous_date(): void
    {
        $worker = User::factory()->create();
        $localNow = CarbonImmutable::parse('2026-10-17 21:30:00', 'UTC')->setTimezone('Asia/Jerusalem');
        $this->travelTo($localNow);

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => 'full',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Asia/Jerusalem', config('app.timezone'));
        $this->assertSame('2026-10-18', Shift::active()->sole()->shift_date->format('Y-m-d'));
    }

    #[DataProvider('ownershipFields')]
    public function test_forged_ownership_fields_are_rejected(string $ownershipField): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => 'full',
            $ownershipField => $otherWorker->id,
        ])->assertSessionHasErrors($ownershipField);

        $this->assertDatabaseCount('shifts', 0);
    }

    public static function ownershipFields(): array
    {
        return [['worker_id'], ['user_id']];
    }

    #[DataProvider('ownershipFields')]
    public function test_cancellation_rejects_forged_ownership_fields(string $ownershipField): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $otherShift = $this->createShiftRecord($otherWorker, '2026-10-18', 'full');

        $this->actingAs($worker)->delete(route('worker.shifts.destroy', ['date' => '2026-10-18']), [
            $ownershipField => $otherWorker->id,
        ])->assertSessionHasErrors($ownershipField);

        $this->assertNull($otherShift->fresh()->cancelled_at);
        $this->assertDatabaseCount('shifts', 1);
    }

    public function test_clearing_a_date_only_changes_the_authenticated_workers_selection(): void
    {
        $worker = User::factory()->create();
        $otherWorker = User::factory()->create();
        $ownShift = $this->createShiftRecord($worker, '2026-10-18');
        $otherShift = $this->createShiftRecord($otherWorker, '2026-10-18', 'full');

        $this->actingAs($worker)->delete(route('worker.shifts.destroy', ['date' => '2026-10-18']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($ownShift->fresh()->cancelled_at);
        $this->assertNull($otherShift->fresh()->cancelled_at);
    }

    public function test_managers_cannot_self_schedule_or_cancel_through_worker_endpoints(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => 'full',
        ])->assertForbidden();
        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-18']))->assertForbidden();

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_guests_cannot_change_shifts_or_block_dates(): void
    {
        $this->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-18',
            'period' => 'full',
        ])->assertRedirect(route('login'));
        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-18']))
            ->assertRedirect(route('login'));
        $this->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-18',
            'confirm_block' => '1',
        ])->assertRedirect(route('login'));
        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-18']), [
            'confirm_unblock' => '1',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('shifts', 0);
        $this->assertDatabaseCount('blocked_dates', 0);
    }

    public function test_multiple_workers_can_choose_the_same_period_without_a_capacity_limit(): void
    {
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $service = app(ShiftSchedulingService::class);

        $service->select($firstWorker, '2026-10-18', '09_13');
        $service->select($secondWorker, '2026-10-18', '09_13');

        $this->assertSame(2, Shift::active()->where('shift_date', '2026-10-18')->count());
    }

    public function test_database_rejects_a_second_active_period_for_the_same_worker_and_date(): void
    {
        $worker = User::factory()->create();
        $this->createShiftRecord($worker, '2026-10-18', '09_13');

        $this->expectException(QueryException::class);

        $this->createShiftRecord($worker, '2026-10-18', 'full');
    }

    public function test_database_allows_multiple_cancelled_rows_and_one_active_selection(): void
    {
        $worker = User::factory()->create();
        $cancelled = ['cancelled_at' => now(), 'cancelled_by' => $worker->id, 'cancellation_reason' => 'worker_cancelled'];
        $this->createShiftRecord($worker, '2026-10-18', '09_13', $cancelled);
        $this->createShiftRecord($worker, '2026-10-18', '09_13', $cancelled);
        $active = $this->createShiftRecord($worker, '2026-10-18', 'full');

        $this->assertSame($active->id, Shift::active()->sole()->id);
        $this->assertDatabaseCount('shifts', 3);
    }

    public function test_scheduling_service_rechecks_an_inactive_worker_instead_of_trusting_a_stale_model(): void
    {
        $worker = User::factory()->create();
        User::query()->whereKey($worker->id)->update(['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        app(ShiftSchedulingService::class)->select($worker, '2026-10-18', 'full');
    }
}
