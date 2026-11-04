<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTramiteRegistroRequest;
use App\Http\Requests\UpdateTramiteRegistroRequest;
use App\Models\TramiteRegistro;
use App\Services\TramiteRegistroService;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro individual de trámites (reemplaza la captura de `entries`).
 */
class TramiteRegistroController extends Controller
{
    public function index(Request $request, TramiteRegistroService $tramites): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = ($data['date'] ?? null) ?: BusinessDay::todayUtc()->toDateString();

        return ApiResponse::ok($tramites->forDate($request->user(), $date));
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
}
