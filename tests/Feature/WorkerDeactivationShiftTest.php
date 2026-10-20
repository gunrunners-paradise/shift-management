<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class WorkerDeactivationShiftTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    #[DataProvider('todayShiftBoundaries')]
    public function test_deactivation_cancels_upcoming_and_in_progress_shifts_but_preserves_completed_history(
        string $time,
        string $period,
        bool $cancelToday,
    ): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 '.$time, 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $past = $this->createShiftRecord($worker, '2026-10-19', 'full');
        $today = $this->createShiftRecord($worker, '2026-10-20', $period);
        $future = $this->createShiftRecord($worker, '2026-10-21', '09_13');
        $previouslyCancelled = $this->createShiftRecord($worker, '2026-10-21', '12_16', [
            'cancelled_at' => CarbonImmutable::parse('2026-10-19 10:00:00', 'Asia/Jerusalem'),
            'cancelled_by' => $worker->id,
            'cancellation_reason' => 'worker_cancelled',
        ]);
        $originalCancellation = $previouslyCancelled->cancelled_at;

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $worker), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($worker->fresh()->is_active);
        $this->assertNull($past->fresh()->cancelled_at);
        $this->assertSame($cancelToday, $today->fresh()->cancelled_at !== null);
        $this->assertNotNull($future->fresh()->cancelled_at);
        $this->assertSame($manager->id, $future->fresh()->cancelled_by);
        $this->assertSame('worker_deactivated', $future->fresh()->cancellation_reason);

        if ($cancelToday) {
            $this->assertSame($manager->id, $today->fresh()->cancelled_by);
            $this->assertSame('worker_deactivated', $today->fresh()->cancellation_reason);
        }

        $this->assertTrue($originalCancellation->equalTo($previouslyCancelled->fresh()->cancelled_at));
        $this->assertSame($worker->id, $previouslyCancelled->fresh()->cancelled_by);
        $this->assertSame('worker_cancelled', $previouslyCancelled->fresh()->cancellation_reason);
        $this->assertDatabaseCount('shifts', 4);
        $this->assertSame($cancelToday ? 1 : 2, Shift::active()->where('worker_id', $worker->id)->count());
    }

    public static function todayShiftBoundaries(): array
    {
        return [
            'before shift starts' => ['08:00:00', '09_13', true],
            'exactly at shift start' => ['12:00:00', '12_16', true],
            'during four hour shift' => ['14:00:00', '11_15', true],
            'during full day' => ['14:00:00', 'full', true],
            'after short shift ended' => ['14:00:00', '09_13', false],
            'exactly at short shift end' => ['14:00:00', '10_14', false],
            'exactly at full day end' => ['16:30:00', 'full', false],
        ];
    }

    public function test_deactivation_rechecks_the_shift_end_after_waiting_for_the_date_lock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:59:59', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create();
        $today = $this->createShiftRecord($worker, '2026-10-20', '09_13');
        $future = $this->createShiftRecord($worker, '2026-10-21', 'full');
        $advancedClock = false;

        DB::listen(function (QueryExecuted $query) use (&$advancedClock): void {
            $sql = strtolower($query->sql);

            if ($advancedClock || ! str_contains($sql, 'calendar_dates') || ! str_contains($sql, 'for update')) {
                return;
            }

            // Simulate the shift ending while the transaction waits for its date
            // lock. Cancellation eligibility must use the clock after that wait.
            $advancedClock = true;
            $this->travelTo(CarbonImmutable::parse('2026-10-20 13:00:00', 'Asia/Jerusalem'));
        });

        $this->actingAs($manager)->patch(route('admin.users.deactivate', $worker), [
            'confirm_deactivation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue($advancedClock);
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertNull($today->fresh()->cancelled_at);
        $this->assertSame('worker_deactivated', $future->fresh()->cancellation_reason);
        $this->assertSame('13:00:00', $future->fresh()->cancelled_at->format('H:i:s'));
    }
}
