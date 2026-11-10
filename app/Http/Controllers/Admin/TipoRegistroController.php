<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTipoRegistroRequest;
use App\Http\Requests\UpdateTipoRegistroRequest;
use App\Models\TipoRegistro;
use App\Support\ApiResponse;
use App\Support\LikeSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo administrable de tipos de registro (INGRESO / ENTREGA / CIERRE).
 */
class TipoRegistroController extends Controller
{
    /** Catálogo activo para los formularios de registro. */
    public function active(): JsonResponse
    {
        return ApiResponse::ok(
            TipoRegistro::active()
                ->orderBy('sortOrder')
                ->orderBy('name')
                ->get(['id', 'name', 'codigo', 'requiereItems', 'requiereTicket', 'sortOrder'])
        );
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = TipoRegistro::query();

        if ($like = LikeSearch::contains($request->query('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhere('codigo', 'like', $like));
        }

        if ($request->query('isActive') !== null && $request->query('isActive') !== '') {
            $q->where('isActive', filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN));
        }

        return ApiResponse::paginated(
            $q->orderBy('sortOrder')->orderBy('name')->paginate($limit)
        );
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(TipoRegistro::findOrFail($id));
    }

    public function store(StoreTipoRegistroRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['isActive'] = $data['isActive'] ?? true;
        $data['requiereItems'] = $data['requiereItems'] ?? false;
        $data['requiereTicket'] = $data['requiereTicket'] ?? true;
        $data['sortOrder'] = $data['sortOrder'] ?? 0;

        return ApiResponse::created(TipoRegistro::create($data));
    }

    /**
     * El código es inmutable una vez creado el tipo de registro.
     */
    public function update(UpdateTipoRegistroRequest $request, string $id): JsonResponse
    {
        $tipo = TipoRegistro::findOrFail($id);

        $data = $request->validated();
        unset($data['codigo']);

        $tipo->update($data);

        return ApiResponse::ok($tipo->fresh());
    }

    /** Baja lógica. */
    public function destroy(string $id): JsonResponse
    {
        TipoRegistro::findOrFail($id)->update(['isActive' => false]);

        return ApiResponse::ok(['message' => 'Tipo de registro desactivado.']);
    }
}
