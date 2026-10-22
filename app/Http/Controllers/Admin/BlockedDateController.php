<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BlockDateRequest;
use App\Http\Requests\UnblockDateRequest;
use App\Services\DateClosureService;
use Illuminate\Http\RedirectResponse;

class BlockedDateController extends Controller
{
    public function store(BlockDateRequest $request, DateClosureService $closures): RedirectResponse
    {
        $data = $request->validated();
        $closures->block($request->user(), $data['shift_date'], $data['reason'] ?? null);

        return redirect()->to(route('admin.users.index').'#blocks')
            ->with('status', 'התאריך נחסם. המשמרות והזמנות העמדות בו בוטלו וההיסטוריה נשמרה.');
    }

    public function destroy(UnblockDateRequest $request, DateClosureService $closures): RedirectResponse
    {
        $closures->unblock($request->user(), $request->validated('shift_date'));

        return redirect()->to(route('admin.users.index').'#blocks')
            ->with('status', 'החסימה הוסרה. משמרות והזמנות שבוטלו לא שוחזרו; ניתן לבחור ולהזמין מחדש.');
    }
}
