<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use App\Services\DateClosureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class DateClosureTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 17:00:00', 'Asia/Jerusalem'));
    }

    public function test_blocking_today_cancels_every_active_selection_and_retains_its_audit_details(): void
    {
        $manager = User::factory()->manager()->create();
        $firstWorker = User::factory()->create();
        $secondWorker = User::factory()->create();
        $firstShift = $this->createShiftRecord($firstWorker, '2026-10-20', '09_13');
        $secondShift = $this->createShiftRecord($secondWorker, '2026-10-20', 'full');
        $tomorrowShift = $this->createShiftRecord($firstWorker, '2026-10-21', '11_15');

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-20',
            'reason' => 'המשרד סגור',
            'confirm_block' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('blocked_dates', [
            'date' => '2026-10-20',
            'reason' => 'המשרד סגור',
            'blocked_by' => $manager->id,
        ]);

        foreach ([$firstShift, $secondShift] as $shift) {
            $shift->refresh();
            $this->assertNotNull($shift->cancelled_at);
            $this->assertSame($manager->id, $shift->cancelled_by);
            $this->assertSame('date_blocked', $shift->cancellation_reason);
            $this->assertSame('המשרד סגור', $shift->cancellation_note);
        }

        // Manager closure cancels all today's selections even after scheduled end.
        $this->assertNull($tomorrowShift->fresh()->cancelled_at);
        $this->assertDatabaseCount('shifts', 3);
    }

    public function test_blocked_dates_reject_selection_and_clearing_on_the_server(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        app(DateClosureService::class)->block($manager, '2026-10-21', null);

        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-21',
            'period' => 'full',
        ])->assertSessionHasErrors('shift_date');
        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-21']))
            ->assertSessionHasErrors('shift_date');

        $this->assertDatabaseCount('shifts', 0);
    }

    public function test_repeated_blocking_is_idempotent_and_unblocking_never_restores_cancelled_shifts(): void
    {
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $original = $this->createShiftRecord($worker, '2026-10-21', '11_15');
        $service = app(DateClosureService::class);
        $service->block($manager, '2026-10-21', 'סגירה זמנית');
        $cancelledAt = $original->fresh()->cancelled_at;

        $this->travel(1)->minutes();
        $service->block($manager, '2026-10-21', 'סגירה זמנית');

        $this->assertTrue($cancelledAt->equalTo($original->fresh()->cancelled_at));
        $this->assertDatabaseCount('blocked_dates', 1);
        $this->assertDatabaseCount('shifts', 1);

        $this->actingAs($manager)->delete(route('admin.blocks.destroy', ['date' => '2026-10-21']), [
            'confirm_unblock' => '1',
        ])->assertSessionHasNoErrors();
        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-21']), [
            'confirm_unblock' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('blocked_dates', ['date' => '2026-10-21']);
        $this->assertDatabaseHas('calendar_dates', ['date' => '2026-10-21']);
        $this->assertTrue($cancelledAt->equalTo($original->fresh()->cancelled_at));
        $this->assertSame(0, Shift::active()->count());

        $this->flushSession();
        $this->actingAs($worker)->post(route('worker.shifts.store'), [
            'shift_date' => '2026-10-21',
            'period' => '11_15',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame($original->id, Shift::active()->sole()->id);
        $this->assertDatabaseCount('shifts', 2);
    }

    public function test_manager_can_block_a_weekend_beyond_the_workers_booking_horizon_without_a_reason(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-12-25',
            'reason' => '',
            'confirm_block' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-12-25', 'reason' => null]);
    }

    public function test_manager_cannot_block_or_reopen_a_past_date(): void
    {
        $manager = User::factory()->manager()->create();
        $service = app(DateClosureService::class);
        $service->block($manager, '2026-10-20', 'סגירה');
        $this->travelTo(CarbonImmutable::parse('2026-10-21 00:01:00', 'Asia/Jerusalem'));

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-19',
            'confirm_block' => '1',
        ])->assertSessionHasErrors('shift_date');
        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-20']), [
            'confirm_unblock' => '1',
        ])->assertSessionHasErrors('shift_date');

        $this->assertDatabaseMissing('blocked_dates', ['date' => '2026-10-19']);
        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-10-20']);
    }

    public function test_block_and_unblock_both_require_explicit_confirmation(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-21',
        ])->assertSessionHasErrors('confirm_block');
        $this->assertDatabaseCount('blocked_dates', 0);

        app(DateClosureService::class)->block($manager, '2026-10-21', null);

        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-21']))
            ->assertSessionHasErrors('confirm_unblock');
        $this->assertDatabaseHas('blocked_dates', ['date' => '2026-10-21']);
    }

    public function test_worker_cannot_block_or_reopen_dates(): void
    {
        $worker = User::factory()->create();

        $this->actingAs($worker)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-21',
            'confirm_block' => '1',
        ])->assertForbidden();
        $this->delete(route('admin.blocks.destroy', ['date' => '2026-10-21']), [
            'confirm_unblock' => '1',
        ])->assertForbidden();

        $this->assertDatabaseCount('blocked_dates', 0);
    }

    public function test_block_reason_is_length_limited_and_escaped_when_displayed(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->post(route('admin.blocks.store'), [
            'shift_date' => '2026-10-21',
            'reason' => str_repeat('א', 151),
            'confirm_block' => '1',
        ])->assertSessionHasErrors('reason');

        app(DateClosureService::class)->block($manager, '2026-10-21', '<script>alert(1)</script>');

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
