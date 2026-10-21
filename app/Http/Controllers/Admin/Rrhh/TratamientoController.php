<?php

namespace App\Http\Controllers\Admin\Rrhh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rrhh\SaveTratamientoRequest;
use App\Models\Tratamiento;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TratamientoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Tratamiento::query()->orderBy('nombre');

        if ($request->query('activo') !== null && $request->query('activo') !== '') {
            $q->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        return ApiResponse::ok($q->get());
    }

    public function store(SaveTratamientoRequest $request): JsonResponse
    {
        return ApiResponse::created(Tratamiento::create($request->validated()));
    }

    public function update(SaveTratamientoRequest $request, string $id): JsonResponse
    {
        $tratamiento = Tratamiento::findOrFail($id);
        $tratamiento->update($request->validated());

        return ApiResponse::ok($tratamiento->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        $tratamiento = Tratamiento::findOrFail($id);
        if ($tratamiento->empleados()->exists()) {
            return ApiResponse::error('No se puede eliminar un tratamiento en uso.', 400);
        }

        $tratamiento->delete();

        return ApiResponse::ok(['message' => 'Tratamiento eliminado.']);
    }
}
