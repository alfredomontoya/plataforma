<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRangeRequest;
use App\Http\Requests\StoreTotalRequest;
use App\Models\DailyTotal;
use App\Models\User;
use App\Services\TotalService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingreso diario total (contrato: carga OPERATOR_INGRESO/ADMIN, lectura ADMIN/JEFE).
 */
class TotalController extends Controller
{
    public function store(StoreTotalRequest $request, TotalService $totals): JsonResponse
    {
        $data = $request->validated();
        $rows = $totals->storeBatch($request->user(), $data['date'], $data['items']);

        return ApiResponse::created($rows);
    }

    /** Catálogo de trámites = servicios INGRESO activos. */
    public function catalog(TotalService $totals): JsonResponse
    {
        return ApiResponse::ok($totals->catalog());
    }

    /**
     * Resuelve nombres contra servicios INGRESO y REGISTRA los faltantes.
     * Body: {names: ["..."]}. Devuelve resolved + catálogo actualizado.
     */
    public function resolve(Request $request, TotalService $totals): JsonResponse
    {
        $data = $request->validate([
            'names' => ['required', 'array', 'min:1', 'max:100'],
            'names.*' => ['required', 'string', 'max:191'],
        ]);

        $resolved = $totals->resolveNames($data['names']);

        return ApiResponse::ok(['resolved' => $resolved, 'catalog' => $totals->catalog()]);
    }

    /** Operadores de ingreso para asociar el supervisor del parte pegado. */
    public function operators(): JsonResponse
    {
        $rows = User::where('role', 'OPERATOR_INGRESO')->where('isActive', true)
            ->orderBy('username')
            ->get(['id', 'username', 'firstName', 'lastName', 'paternalSurname', 'maternalSurname']);

        return ApiResponse::ok($rows);
    }

    /** Totales propios del operador autenticado en un día (precarga). */
    public function me(Request $request): JsonResponse
    {
        $date = $request->query('date', BusinessDay::todayUtc()->toDateString());
        $day = BusinessDay::parseUtcDay($date);

        $rows = DailyTotal::with('service')->where('userId', $request->user()->id)
            ->where('date', '>=', $day)
            ->where('date', '<', $day->copy()->addDay())
            ->get();

        return ApiResponse::ok($rows);
    }

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
