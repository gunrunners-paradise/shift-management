<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportWorkdaysRequest;
use App\Services\WorkdayCsvReportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkdayReportController extends Controller
{
    /** Generate only for this explicit, validated manager download request. */
    public function __invoke(ExportWorkdaysRequest $request, WorkdayCsvReportService $reports): StreamedResponse
    {
        $startDate = $request->validated('start_date');
        $endDate = $request->validated('end_date');
        $receiptMonth = $request->validated('receipt_month');

        // Prepare before response headers: a stale manager must receive a real
        // authorization error, never a partly downloaded CSV with a 200 status.
        $csv = $reports->prepare($request->user(), $startDate, $endDate, $receiptMonth);

        return response()->streamDownload(function () use ($csv): void {
            try {
                fpassthru($csv);
            } finally {
                fclose($csv);
            }
        }, 'scheduled-workdays-'.$startDate.'-to-'.$endDate.'-receipts-'.$receiptMonth.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
