<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeactivateDeskRequest;
use App\Http\Requests\StoreDeskRequest;
use App\Models\Desk;
use App\Services\DeskManagementService;
use Illuminate\Http\RedirectResponse;

class DeskController extends Controller
{
    public function store(StoreDeskRequest $request, DeskManagementService $desks): RedirectResponse
    {
        $desks->create($request->user(), $request->validated('name'));

        return redirect()->to(route('admin.users.index').'#desks')->with('status', 'העמדה נוספה בהצלחה.');
    }

    public function update(StoreDeskRequest $request, Desk $desk, DeskManagementService $desks): RedirectResponse
    {
        $desks->rename($request->user(), $desk, $request->validated('name'));

        return redirect()->to(route('admin.users.index').'#desks')->with('status', 'שם העמדה עודכן. היסטוריית ההזמנות נשמרה.');
    }

    public function deactivate(DeactivateDeskRequest $request, Desk $desk, DeskManagementService $desks): RedirectResponse
    {
        $desks->deactivate($request->user(), $desk);

        return redirect()->to(route('admin.users.index').'#desks')
            ->with('status', 'העמדה הושבתה. הזמנות עתידיות והזמנות שכבר החלו בוטלו; ההיסטוריה נשמרה.');
    }
}
