<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMonthlyReceiptRequest;
use App\Services\MonthlyReceiptService;
use Illuminate\Http\RedirectResponse;

class WorkerReceiptController extends Controller
{
    public function update(UpdateMonthlyReceiptRequest $request, MonthlyReceiptService $receipts): RedirectResponse
    {
        $data = $request->validated();
        $receipts->set($request->user(), $data['month'], $request->boolean('has_receipts'));

        return redirect()->to(route('worker.home', ['week' => $data['week'] ?? null]).'#worker-receipts')
            ->with('status', 'מצב הקבלות לחודש הנוכחי נשמר.');
    }
}
