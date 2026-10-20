<?php

namespace Tests\Concerns;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait CreatesShiftRecords
{
    /**
     * Insert explicit historical fixtures without applying today's booking window.
     * Production mutations always use the scheduling service; tests need past
     * and cancelled records to verify that those records remain untouched.
     */
    protected function createShiftRecord(User $worker, string $date, string $period = '09_13', array $overrides = []): Shift
    {
        DB::table('calendar_dates')->insertOrIgnore(['date' => $date]);
        $shiftId = DB::table('shifts')->insertGetId(array_replace([
            'worker_id' => $worker->id,
            'shift_date' => $date,
            'period' => $period,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'cancellation_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Shift::query()->findOrFail($shiftId);
    }
}
