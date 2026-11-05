<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTramiteRegistroRequest;
use App\Http\Requests\TramiteReportRequest;
use App\Http\Requests\UpdateTramiteRegistroRequest;
use App\Models\TramiteRegistro;
use App\Services\TramiteRegistroService;
use App\Services\TramiteReportService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registro individual de trámites (reemplaza la captura de `entries`).
 */
class TramiteRegistroController extends Controller
{
    public function index(Request $request, TramiteRegistroService $tramites): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $today = BusinessDay::todayUtc()->toDateString();
        $from = $data['from'] ?? $data['date'] ?? $today;
        $to = $data['to'] ?? $data['date'] ?? $today;
        $search = $data['search'] ?? null;
        $user = $request->user();

        $paginator = $tramites->paginateRange(
            $user,
            $from,
            $to,
            $search,
            (int) ($data['limit'] ?? 20),
            (int) ($data['page'] ?? 1),
        );

        return ApiResponse::paginated($paginator, [
            'activos' => $tramites->activeCount($user, $from, $to, $search),
            'porTipo' => $tramites->summaryByType($user, $from, $to, $search),
        ]);
    }

    public function exportPdf(
        TramiteReportRequest $request,
        TramiteReportService $reports,
    ): Response {
        $data = $request->validated();

        return $reports->pdf($request->user(), $data['from'], $data['to'], $data['search'] ?? null);
    }

    public function exportExcel(
        TramiteReportRequest $request,
        TramiteReportService $reports,
    ): Response {
        $data = $request->validated();

        return $reports->excel($request->user(), $data['from'], $data['to'], $data['search'] ?? null);
    }

    public function catalog(Request $request, TramiteRegistroService $tramites): JsonResponse
    {
        return ApiResponse::ok($tramites->catalog($request->user()));
    }

    public function store(StoreTramiteRegistroRequest $request, TramiteRegistroService $tramites): JsonResponse
    {
        return ApiResponse::created($tramites->create($request->user(), $request->validated()));
    }

    public function update(
        UpdateTramiteRegistroRequest $request,
        string $id,
        TramiteRegistroService $tramites,
    ): JsonResponse {
        $registro = TramiteRegistro::findOrFail($id);

        return ApiResponse::ok($tramites->update($request->user(), $registro, $request->validated()));
    }

    /** Eliminación lógica: marca el registro como inactivo. */
    public function destroy(
        Request $request,
        string $id,
        TramiteRegistroService $tramites,
    ): JsonResponse {
        $registro = TramiteRegistro::findOrFail($id);

        return ApiResponse::ok($tramites->deactivate($request->user(), $registro));
    }
}
