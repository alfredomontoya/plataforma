<?php

namespace App\Http\Controllers\Admin\Rrhh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rrhh\SaveAreaRequest;
use App\Models\Area;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Area::query()
            ->with('padre:id,nombre,codigo')
            ->withCount(['puestos', 'hijos'])
            ->orderBy('nombre');

        if ($search = $request->query('search')) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('nombre', 'like', $like)
                ->orWhere('codigo', 'like', $like)
                ->orWhere('descripcion', 'like', $like));
        }

        return ApiResponse::ok($q->get());
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(
            Area::with([
                'padre:id,nombre,codigo',
                'hijos' => fn ($q) => $q->orderBy('nombre'),
                'puestos' => fn ($q) => $q->orderBy('nivel')->orderBy('nombre'),
            ])->withCount(['puestos', 'hijos'])->findOrFail($id)
        );
    }

    public function store(SaveAreaRequest $request): JsonResponse
    {
        return ApiResponse::created(
            Area::create($request->validated())
                ->load('padre:id,nombre,codigo')
                ->loadCount(['puestos', 'hijos'])
        );
    }

    public function update(SaveAreaRequest $request, string $id): JsonResponse
    {
        $area = Area::findOrFail($id);
        $area->update($request->validated());

        return ApiResponse::ok(
            $area->fresh()
                ->load('padre:id,nombre,codigo')
                ->loadCount(['puestos', 'hijos'])
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $area = Area::withCount(['puestos', 'hijos'])->findOrFail($id);

        if ($area->puestos_count > 0) {
            return ApiResponse::error('No se puede eliminar un área con puestos asignados.', 400);
        }
        if ($area->hijos_count > 0) {
            return ApiResponse::error('No se puede eliminar un área que tiene subáreas.', 400);
        }

        $area->delete();

        return ApiResponse::ok(['message' => 'Área eliminada.']);
    }
}
