<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Services\TotalService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agregados de trámites para el dashboard (día/semana/rango/tendencia).
 * La captura de totales diarios se retiró: la fuente es `tramite_registros`.
 */
class TotalController extends Controller
{
    public function day(Request $request, TotalService $totals): JsonResponse
    {
        $date = $request->query('date', BusinessDay::todayUtc()->toDateString());
        BusinessDay::parseUtcDay($date);

        return ApiResponse::ok($totals->day($date));
    }

    public function week(Request $request, TotalService $totals): JsonResponse
    {
        $request->validate(['weekStart' => ['required', 'date_format:Y-m-d']]);

        return ApiResponse::ok($totals->week($request->query('weekStart')));
    }

    public function range(DashboardRangeRequest $request, TotalService $totals): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::ok($totals->range($data['from'], $data['to']));
    }

    public function daily(DashboardRangeRequest $request, TotalService $totals): JsonResponse
    {
        $data = $request->validated();

        return ApiResponse::ok($totals->daily($data['from'], $data['to']));
    }
}
