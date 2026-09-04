<?php

namespace App\Http\Controllers\Jefe;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRangeRequest;
use App\Services\DashboardService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard ADMIN/JEFE (contrato 04 § Informes dashboard).
 */
class DashboardController extends Controller
{
    public function summary(Request $request, DashboardService $dash): JsonResponse
    {
        $date = $request->query('date', BusinessDay::todayUtc()->toDateString());
        BusinessDay::parseUtcDay($date);

        return ApiResponse::ok($dash->day($date));
    }

    public function weekly(Request $request, DashboardService $dash): JsonResponse
    {
        $request->validate(['weekStart' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::ok($dash->week($request->query('weekStart')));
    }

    public function range(DashboardRangeRequest $request, DashboardService $dash): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::ok($dash->range($data['from'], $data['to']));
    }
}
