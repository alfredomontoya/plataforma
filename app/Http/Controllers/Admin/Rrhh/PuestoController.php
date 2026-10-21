<?php

namespace App\Http\Controllers\Admin\Rrhh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rrhh\SavePuestoRequest;
use App\Models\Puesto;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PuestoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Puesto::query()->with('area')->withCount('asignaciones');

        if ($areaId = $request->query('areaId')) {
            $q->where('areaId', $areaId);
        }
        if ($search = $request->query('search')) {
            $q->where('nombre', 'like', '%'.$search.'%');
        }

        return ApiResponse::ok($q->orderBy('areaId')->orderBy('nivel')->orderBy('nombre')->get());
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(Puesto::with(['area'])->withCount('asignaciones')->findOrFail($id));
    }

    public function store(SavePuestoRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($this->nombreDuplicado($data['areaId'], $data['nombre'])) {
            return ApiResponse::error('Ya existe un puesto con ese nombre en el área.', 400);
        }

        return ApiResponse::created(Puesto::create($data)->load('area'));
    }

    public function update(SavePuestoRequest $request, string $id): JsonResponse
    {
        $puesto = Puesto::findOrFail($id);
        $data = $request->validated();
        $areaId = $data['areaId'] ?? $puesto->areaId;
        $nombre = $data['nombre'] ?? $puesto->nombre;

        if ($this->nombreDuplicado($areaId, $nombre, $puesto->id)) {
            return ApiResponse::error('Ya existe un puesto con ese nombre en el área.', 400);
        }

        $puesto->update($data);

        return ApiResponse::ok($puesto->fresh()->load('area')->loadCount('asignaciones'));
    }

    public function destroy(string $id): JsonResponse
    {
        $puesto = Puesto::withCount('asignaciones')->findOrFail($id);
        if ($puesto->asignaciones_count > 0) {
            return ApiResponse::error('No se puede eliminar un puesto con asignaciones.', 400);
        }

        $puesto->delete();

        return ApiResponse::ok(['message' => 'Puesto eliminado.']);
    }

    private function nombreDuplicado(string $areaId, string $nombre, ?string $ignoreId = null): bool
    {
        return Puesto::where('areaId', $areaId)
            ->where('nombre', $nombre)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
