<?php

namespace Tests\Concerns;

use App\Models\Desk;
use App\Models\DeskReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait CreatesDeskReservations
{
    /**
     * Insert explicit history fixtures without applying today's booking window.
     * Production reservations use the service; historical and cancelled rows
     * are necessary to verify that later administrative actions preserve them.
     */
    protected function createDeskReservation(
        User $worker,
        Desk $desk,
        string $date = '2026-10-21',
        string $start = '09:00',
        string $end = '10:00',
        array $overrides = [],
    ): DeskReservation {
        DB::table('calendar_dates')->insertOrIgnore(['date' => $date]);
        $reservationId = DB::table('desk_reservations')->insertGetId(array_replace([
            'worker_id' => $worker->id,
            'desk_id' => $desk->id,
            'reservation_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
            'cancellation_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return DeskReservation::query()->findOrFail($reservationId);
    }
}
