<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de servicios (contrato 04 § Servicios).
 */
class ServiceController extends Controller
{
    /** Catálogo para operadores: activos del tipo, ordenados por nombre. */
    public function byType(string $type): JsonResponse
    {
        abort_unless(in_array($type, ['INGRESO', 'ENTREGA'], true), 404, 'Tipo no encontrado.');

        return ApiResponse::ok(
            Service::where('type', $type)->where('isActive', true)
                ->orderBy('name')->get()
        );
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = Service::query();

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('codigo', 'like', "%{$search}%")
                ->orWhere('abreviation', 'like', "%{$search}%"));
        }
        if ($request->query('type')) {
            $q->where('type', $request->query('type'));
        }
        if ($request->query('isActive') !== null && $request->query('isActive') !== '') {
            $q->where('isActive', filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN));
        }

        return ApiResponse::paginated($q->orderBy('sortOrder')->orderBy('name')->paginate($limit));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(Service::findOrFail($id));
    }

    public function store(CreateServiceRequest $request): JsonResponse
    {
        return ApiResponse::created(Service::create($request->validated()));
    }

    public function update(UpdateServiceRequest $request, string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->update($request->validated());

        return ApiResponse::ok($service->fresh());
    }

    public function destroy(string $id): JsonResponse
    {
        Service::findOrFail($id)->update(['isActive' => false]);

        return ApiResponse::ok(['message' => 'Servicio desactivado.']);
    }
}
