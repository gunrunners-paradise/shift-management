<?php

namespace App\Services;

use App\Models\MonthlyReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonthlyReceiptService
{
    /**
     * Save the authenticated worker's current-month yes/no receipt state.
     * Lock the worker before reading or writing its receipt row: this serializes
     * simultaneous first writes and deactivation even when no receipt exists.
     * The unique worker/month index remains a second database integrity guard.
     */
    public function set(User $actor, string $month, bool $hasReceipts): MonthlyReceipt
    {
        $monthDate = $this->monthDate($month);
        $this->requireCurrentMonth($monthDate);

        return DB::transaction(function () use ($actor, $monthDate, $hasReceipts): MonthlyReceipt {
            $worker = User::query()->lockForUpdate()->find($actor->getKey());

            if ($worker === null || ! $worker->is_active || $worker->role !== 'worker') {
                throw new AuthorizationException('רק חשבון עובד פעיל יכול לעדכן קבלות.');
            }

            // A request can wait across the Israel month boundary for this lock.
            // Reject its old form instead of changing a now-historical month.
            $this->requireCurrentMonth($monthDate);

            return MonthlyReceipt::updateOrCreate([
                'worker_id' => $worker->getKey(),
                'month' => $monthDate->toDateString(),
            ], [
                'has_receipts' => $hasReceipts,
            ]);
        }, 3);
    }

    /**
     * Read one selected month for the manager dashboard, including inactive
     * worker identities so earlier receipt states remain visible. A missing row
     * is false, never a third state. Count the same joined snapshot as the list.
     * The controller's manager middleware authorizes access to this report.
     */
    public function report(string $month): array
    {
        $monthDate = $this->monthDate($month);
        $workers = User::query()
            ->leftJoin('monthly_receipts', function (JoinClause $join) use ($monthDate): void {
                $join->on('monthly_receipts.worker_id', '=', 'users.id')
                    ->where('monthly_receipts.month', $monthDate->toDateString());
            })
            ->where('users.role', 'worker')
            ->orderBy('users.first_name')->orderBy('users.last_name')->orderBy('users.id')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.is_active', 'monthly_receipts.has_receipts']);

        $rows = [];
        $yesCount = 0;

        foreach ($workers as $worker) {
            $hasReceipts = (bool) $worker->has_receipts;
            $rows[] = [
                'name' => $worker->fullName(),
                'is_active' => $worker->is_active,
                'has_receipts' => $hasReceipts,
            ];

            if ($hasReceipts) {
                $yesCount++;
            }
        }

        $totalCount = count($rows);

        return [
            'month' => $monthDate->format('Y-m'),
            'month_label' => $monthDate->locale('he')->translatedFormat('F Y'),
            'workers' => $rows,
            'yes_count' => $yesCount,
            'no_count' => $totalCount - $yesCount,
            'total_count' => $totalCount,
        ];
    }

    /** Parse the input once into the canonical first date of a calendar month. */
    private function monthDate(string $month): CarbonImmutable
    {
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m']])->validate();

        return CarbonImmutable::createFromFormat('!Y-m', $month, config('app.timezone'));
    }

    private function requireCurrentMonth(CarbonImmutable $month): void
    {
        $currentMonth = CarbonImmutable::today(config('app.timezone'))->startOfMonth();

        if (! $month->isSameMonth($currentMonth, true)) {
            throw ValidationException::withMessages([
                'month' => 'ניתן לעדכן קבלות רק עבור החודש הנוכחי. יש לרענן את העמוד ולנסות שוב.',
            ]);
        }
    }
}
