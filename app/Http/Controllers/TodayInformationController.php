<?php

namespace App\Http\Controllers;

use App\Services\TodayInformationService;
use Illuminate\Http\JsonResponse;

class TodayInformationController extends Controller
{
    /** Worker-only JSON for the panels below the calendar; no booking mutations. */
    public function __invoke(TodayInformationService $information): JsonResponse
    {
        return response()->json($information->forToday());
    }
}
