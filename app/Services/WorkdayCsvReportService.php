<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class WorkdayCsvReportService
{
    /**
     * Prepare one requested report from validated inclusive local DATE bounds.
     * The caller owns the returned rewound stream and must close it after use.
     * All authorization and database work finishes before download headers.
     * This request-local buffer is discarded after transfer; it is never a
     * saved report, recurring export or background task.
     *
     * @return resource
     */
    public function prepare(User $actor, string $startDate, string $endDate): mixed
    {
        // Small reports stay in memory. PHP temporarily spills larger output
        // to private system temporary storage and removes it when closed.
        $csv = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($csv === false) {
            throw new RuntimeException('Unable to allocate the requested CSV stream.');
        }

        try {
            DB::transaction(function () use ($actor, $startDate, $endDate, $csv): void {
                // Lock only the requesting manager, not the global manager gate
                // or workers/dates. Deactivation cannot invalidate authority
                // during generation, and slow client transfer holds no locks.
                $manager = User::query()->lockForUpdate()->find($actor->getKey());

                if ($manager === null || ! $manager->is_active || ! $manager->isManager()
                    || $manager->session_version !== (int) $actor->session_version) {
                    throw new AuthorizationException('רק מנהל/ת פעיל/ה יכול/ה להוריד דוח.');
                }

                // The BOM lets Excel recognize Hebrew UTF-8 without importing
                // with a manually selected encoding.
                $this->writeBytes($csv, "\xEF\xBB\xBF");
                $this->writeRow($csv, ['שם העובד', 'מספר ימי עבודה', 'תאריכי עבודה']);
                $this->writeWorkerRows($csv, $startDate, $endDate);
            });

            if (! rewind($csv)) {
                throw new RuntimeException('Unable to rewind the requested CSV stream.');
            }

            return $csv;
        } catch (Throwable $exception) {
            fclose($csv);

            throw $exception;
        }
    }

    /**
     * Read workers and qualifying shifts in a single consistent InnoDB SELECT.
     * Conditions stay in JOIN ON so workers with no qualifying days still have
     * a zero row. Inactive workers remain included; managers never become rows.
     * The active-slot unique index guarantees one non-canceled shift per date.
     * Query Builder avoids Eloquent hydration; PDO still buffers this one query.
     *
     * @param  resource  $csv
     */
    private function writeWorkerRows(mixed $csv, string $startDate, string $endDate): void
    {
        $records = DB::table('users')
            ->leftJoin('shifts', function (JoinClause $join) use ($startDate, $endDate): void {
                $join->on('shifts.worker_id', '=', 'users.id')
                    ->whereNull('shifts.cancelled_at')
                    ->where('shifts.shift_date', '>=', $startDate)
                    ->where('shifts.shift_date', '<=', $endDate);
            })
            ->where('users.role', 'worker')
            ->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('users.id')
            ->orderBy('shifts.shift_date')
            ->select(['users.id as worker_id', 'users.first_name', 'users.last_name', 'shifts.shift_date'])
            ->cursor();

        $workerId = null;
        $workerName = '';
        $dates = [];

        foreach ($records as $record) {
            // Group by stable identity, even when two workers share a name.
            if ($workerId !== $record->worker_id) {
                if ($workerId !== null) {
                    $this->writeWorker($csv, $workerName, $dates);
                }

                $workerId = $record->worker_id;
                $workerName = $record->first_name.' '.$record->last_name;
                $dates = [];
            }

            if ($record->shift_date !== null) {
                $dates[] = CarbonImmutable::createFromFormat('!Y-m-d', $record->shift_date, config('app.timezone'))
                    ->format('d/m/Y');
            }
        }

        if ($workerId !== null) {
            $this->writeWorker($csv, $workerName, $dates);
        }
    }

    /** @param resource $csv */
    private function writeWorker(mixed $csv, string $name, array $dates): void
    {
        $this->writeRow($csv, [$this->safeWorkerName($name), (string) count($dates), implode(', ', $dates)]);
    }

    /** Neutralize spreadsheet formula/control prefixes in an untrusted name. */
    private function safeWorkerName(string $name): string
    {
        // Check past leading Unicode spaces/format marks too. Quoting CSV alone
        // does not stop Excel from interpreting a name as a formula.
        $formulaPrefix = preg_match('/\A[\p{Z}\s\p{Cf}]*[=+\-@＝＋－＠]/u', $name) === 1;
        $leadingControl = preg_match('/\A[\p{Z}\p{Cf} ]*[\x00-\x1F\x7F]/u', $name) === 1;

        return $formulaPrefix || $leadingControl ? "'".$name : $name;
    }

    /**
     * Quote every cell, including empty/single-date lists. Native fputcsv only
     * quotes when needed, while the report explicitly requires a quoted list.
     * Double embedded quotes and use CRLF without backslash-specific escaping.
     *
     * @param  resource  $csv
     */
    private function writeRow(mixed $csv, array $cells): void
    {
        $quotedCells = [];

        foreach ($cells as $cell) {
            $quotedCells[] = '"'.str_replace('"', '""', $cell).'"';
        }

        $this->writeBytes($csv, implode(',', $quotedCells)."\r\n");
    }

    /** Handle partial writes or a full temporary volume before any download. */
    private function writeBytes(mixed $csv, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($csv, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the requested CSV stream.');
            }

            $offset += $written;
        }
    }
}
