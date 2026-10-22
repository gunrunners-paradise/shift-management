<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminDashboardRequest;
use App\Http\Requests\DeactivateUserRequest;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ReactivateUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Models\BlockedDate;
use App\Models\CalendarEvent;
use App\Models\Desk;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountManagementService;
use App\Services\MonthlyReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(AdminDashboardRequest $request, MonthlyReceiptService $receipts): View
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();
        $reportMonth = $request->validated('receipts_month') ?: $today->format('Y-m');

        // Hide only past months, using the actual Israel month rather than the
        // receipts picker. Keep all future dates visible and apply the cutoff
        // before pagination so totals match the lists. History remains stored.
        $currentMonthStart = $today->startOfMonth()->toDateString();

        return view('admin.users.index', [
            'users' => User::orderByDesc('is_active')->orderBy('id')->paginate(20)->withQueryString(),
            'activeManagerCount' => User::where('role', 'manager')->where('is_active', true)->count(),
            'blockedDates' => BlockedDate::where('date', '>=', $currentMonthStart)
                ->orderBy('date')->paginate(10, ['*'], 'blocks_page')->withQueryString(),
            'calendarEvents' => CalendarEvent::where('date', '>=', $currentMonthStart)
                ->orderBy('date')->orderBy('id')
                ->paginate(10, ['*'], 'events_page')->withQueryString(),
            'receiptReport' => $receipts->report($reportMonth),
            'managedDesks' => Desk::query()->withCount([
                'reservations as upcoming_reservations_count' => function ($query) use ($today, $now): void {
                    $query->active()->where(function ($dates) use ($today, $now): void {
                        $dates->where('reservation_date', '>', $today->toDateString())
                            ->orWhere(function ($currentDay) use ($today, $now): void {
                                $currentDay->where('reservation_date', $today->toDateString())->where('start_time', '>', $now->format('H:i:s'));
                            });
                    });
                },
                'reservations as in_progress_reservations_count' => function ($query) use ($today, $now): void {
                    $query->active()->where('reservation_date', $today->toDateString())
                        ->where('start_time', '<=', $now->format('H:i:s'))->where('end_time', '>', $now->format('H:i:s'));
                },
            ])->orderByDesc('is_active')->orderBy('id')->paginate(12, ['*'], 'desks_page')->withQueryString(),
            'today' => $today,
        ]);
    }

    public function store(StoreUserRequest $request, AccountManagementService $accounts): RedirectResponse
    {
        $accounts->create($request->user(), $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'החשבון נוצר בהצלחה.');
    }

    public function deactivate(DeactivateUserRequest $request, User $user, AccountManagementService $accounts): RedirectResponse
    {
        $accounts->deactivate($request->user(), $user);

        if ($request->user()->is($user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()->route('admin.users.index')->with('status', 'החשבון הושבת.');
    }

    public function reactivate(ReactivateUserRequest $request, User $user, AccountManagementService $accounts): RedirectResponse
    {
        $accounts->reactivate($request->user(), $user);

        return redirect()->to(route('admin.users.index').'#account-list')
            ->with('status', 'החשבון הופעל מחדש. ניתן להתחבר עם שם המשתמש והסיסמה הקיימים.');
    }

    public function destroy(DeleteUserRequest $request, User $user, AccountDeletionService $accounts): RedirectResponse
    {
        $accounts->delete($request->user(), $user);

        return redirect()->to(route('admin.users.index').'#account-list')
            ->with('status', 'החשבון, המשמרות, הזמנות העמדות והקבלות שלו נמחקו.');
    }
}
