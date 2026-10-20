<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminDashboardRequest;
use App\Http\Requests\DeactivateUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Models\BlockedDate;
use App\Models\CalendarEvent;
use App\Models\User;
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
        $today = CarbonImmutable::today(config('app.timezone'));
        $reportMonth = $request->validated('receipts_month') ?: $today->format('Y-m');

        return view('admin.users.index', [
            'users' => User::orderByDesc('is_active')->orderBy('id')->paginate(20)->withQueryString(),
            'activeManagerCount' => User::where('role', 'manager')->where('is_active', true)->count(),
            'blockedDates' => BlockedDate::orderBy('date')->paginate(10, ['*'], 'blocks_page')->withQueryString(),
            'calendarEvents' => CalendarEvent::orderBy('date')->orderBy('id')
                ->paginate(10, ['*'], 'events_page')->withQueryString(),
            'receiptReport' => $receipts->report($reportMonth),
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
}
