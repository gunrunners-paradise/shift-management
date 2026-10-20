<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class CalendarDateLock
{
    /**
     * Serialize all mutations for one validated local Y-m-d calendar date.
     * Call inside a transaction; for multiple dates, acquire chronologically.
     * A permanent key solves the absent-blocked-row race. The no-op upsert
     * obtains an exclusive duplicate-key lock instead of INSERT IGNORE's shared
     * lock, avoiding competing shared-to-exclusive upgrades on existing dates.
     */
    public function acquire(string $date): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A calendar date lock requires an active database transaction.');
        }

        DB::table('calendar_dates')->upsert([['date' => $date]], ['date'], ['date']);
        DB::table('calendar_dates')->where('date', $date)->lockForUpdate()->first();
    }
}
