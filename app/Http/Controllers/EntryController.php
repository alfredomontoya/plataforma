<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEntryRequest;
use App\Http\Requests\UpdateEntryRequest;
use App\Models\Entry;
use App\Services\EntryService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro diario (contrato 04 § Entradas).
 */
class EntryController extends Controller
{
    public function store(CreateEntryRequest $request, EntryService $entries): JsonResponse
    {
        $data = $request->validated();
        $rows = $entries->storeBatch($request->user(), $data['date'], $data['type'], $data['items']);

        return ApiResponse::created($rows);
    }

    public function update(UpdateEntryRequest $request, string $id, EntryService $entries): JsonResponse
    {
        $entry = $entries->updateQuantity(
            $request->user(), Entry::findOrFail($id), $request->validated()['quantity']
        );

        return ApiResponse::ok($entry);
    }

    /** Entradas propias del operador autenticado. */
    public function me(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = Entry::with('service')->where('userId', $request->user()->id);
        $this->applyDateFilters($q, $request);

        if ($request->query('type')) {
            $q->where('type', $request->query('type'));
        }

        return ApiResponse::paginated($q->orderBy('date', 'desc')->paginate($limit));
    }

    /** Todas (ADMIN/JEFE). */
    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = Entry::with(['service', 'user']);
        $this->applyDateFilters($q, $request);

        foreach (['userId', 'type', 'serviceId'] as $f) {
            if ($request->query($f)) {
                $q->where($f, $request->query($f));
            }
        }

        return ApiResponse::paginated($q->orderBy('date', 'desc')->paginate($limit));
    }

    public function summaryDaily(Request $request, \App\Services\DashboardService $dash): JsonResponse
    {
        $date = $request->query('date', BusinessDay::todayUtc()->toDateString());
        $type = $request->query('type', 'INGRESO');
        $day = $dash->day($date);
        $key = strtolower($type) === 'entrega' ? 'entrega' : 'ingreso';

        return ApiResponse::ok([
            'date' => $date, 'type' => strtoupper($type),
            'items' => $day[$key], 'total' => $day['totalIngreso'] + $day['totalEntrega'],
        ]);
    }

    public function summaryByOperator(Request $request, \App\Services\DashboardService $dash): JsonResponse
    {
        $date = $request->query('date', BusinessDay::todayUtc()->toDateString());
        $day = $dash->day($date);

        return ApiResponse::ok(['date' => $date, 'operators' => $day['operators']]);
    }

    public function summaryWeekly(Request $request, \App\Services\DashboardService $dash): JsonResponse
    {
        $request->validate(['weekStart' => ['required', 'date_format:Y-m-d']]);
        $week = $dash->week($request->query('weekStart'));

        return ApiResponse::ok($week);
    }

    private function applyDateFilters($query, Request $request): void
    {
        if ($date = $request->query('date')) {
            $day = BusinessDay::parseUtcDay($date);
            $query->where('date', '>=', $day)->where('date', '<', $day->copy()->addDay());
        }
        if ($from = $request->query('from')) {
            $query->where('date', '>=', BusinessDay::parseUtcDay($from));
        }
        if ($to = $request->query('to')) {
            $query->where('date', '<', BusinessDay::parseUtcDay($to)->addDay());
        }
    }
}
