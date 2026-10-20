<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarWeekRequest;
use App\Http\Requests\ClearShiftRequest;
use App\Http\Requests\StoreShiftRequest;
use App\Models\BlockedDate;
use App\Models\MonthlyReceipt;
use App\Models\Shift;
use App\Services\ShiftSchedulingService;
use App\Services\TodayInformationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ShiftCalendarController extends Controller
{
    /**
     * Render five local workdays and only the signed-in worker's active choices.
     * Browsing another week never changes the real date or the editable horizon.
     * Blade builds the complete grid, so reading and changing it also works without JS.
     */
    public function index(CalendarWeekRequest $request, TodayInformationService $information): View
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $requestedDate = $request->validated('week') ?: $today->toDateString();
        $weekStart = CarbonImmutable::createFromFormat('!Y-m-d', $requestedDate, config('app.timezone'))
            ->startOfWeek(CarbonInterface::SUNDAY);
        $weekEnd = $weekStart->addDays(4);
        $lastEditableDate = $today->addDays(config('shifts.booking_horizon_days'));

        $selections = [];
        $shifts = Shift::active()->where('worker_id', $request->user()->getKey())
            ->whereBetween('shift_date', [$weekStart->toDateString(), $weekEnd->toDateString()])->get();

        foreach ($shifts as $shift) {
            $selections[$shift->shift_date->toDateString()] = $shift->period;
        }

        $blocks = [];
        $blockedDates = BlockedDate::whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])->get();

        foreach ($blockedDates as $blockedDate) {
            $blocks[$blockedDate->date->toDateString()] = $blockedDate;
        }

        $days = [];
        $weekdays = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי'];

        foreach ($weekdays as $offset => $weekday) {
            $date = $weekStart->addDays($offset);
            $dateKey = $date->toDateString();
            $block = $blocks[$dateKey] ?? null;
            $days[] = [
                'date' => $date,
                'weekday' => $weekday,
                'date_key' => $dateKey,
                'is_today' => $date->isSameDay($today),
                'editable' => $date->betweenIncluded($today, $lastEditableDate),
                'blocked' => $block !== null,
                'block_reason' => $block?->reason,
                'selected_period' => $selections[$dateKey] ?? null,
            ];
        }

        return view('worker.home', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'today' => $today,
            'previousWeek' => $weekStart->subWeek(),
            'nextWeek' => $weekStart->addWeek(),
            'periods' => config('shifts.periods'),
            'days' => $days,
            'todayInformation' => $information->forToday(),
            // Receipts follow the actual local month, never the browsed week.
            'receiptMonth' => $today->format('Y-m'),
            'receiptMonthLabel' => $today->locale('he')->translatedFormat('F Y'),
            'hasReceipts' => (bool) MonthlyReceipt::where('worker_id', $request->user()->getKey())
                ->where('month', $today->startOfMonth()->toDateString())->value('has_receipts'),
        ]);
    }

    public function store(StoreShiftRequest $request, ShiftSchedulingService $scheduling): RedirectResponse
    {
        $data = $request->validated();
        $scheduling->select($request->user(), $data['shift_date'], $data['period']);

        return redirect()->route('worker.home', ['week' => $data['week'] ?? $data['shift_date']])
            ->with('status', 'המשמרת נשמרה.');
    }

    public function destroy(ClearShiftRequest $request, ShiftSchedulingService $scheduling): RedirectResponse
    {
        $data = $request->validated();
        $scheduling->clear($request->user(), $data['shift_date']);

        return redirect()->route('worker.home', ['week' => $data['week'] ?? $data['shift_date']])
            ->with('status', 'הבחירה בוטלה. היום נשאר בלי משמרת.');
    }
}
