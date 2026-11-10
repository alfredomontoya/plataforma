<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEntregaItemRequest;
use App\Http\Requests\UpdateEntregaItemRequest;
use App\Models\EntregaItem;
use App\Support\ApiResponse;
use App\Support\LikeSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo administrable de ítems de entrega.
 */
class EntregaItemController extends Controller
{
    /** Catálogo activo para los formularios de registro. */
    public function active(): JsonResponse
    {
        return ApiResponse::ok(
            EntregaItem::active()
                ->orderBy('sortOrder')
                ->orderBy('name')
                ->get(['id', 'name', 'codigo', 'sortOrder'])
        );
    }

    public function index(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $q = EntregaItem::query();

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
        return ApiResponse::ok(EntregaItem::findOrFail($id));
    }

    public function store(StoreEntregaItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['isActive'] = $data['isActive'] ?? true;
        $data['sortOrder'] = $data['sortOrder'] ?? 0;

        return ApiResponse::created(EntregaItem::create($data));
    }

    public function update(UpdateEntregaItemRequest $request, string $id): JsonResponse
    {
        $item = EntregaItem::findOrFail($id);
        $item->update($request->validated());

        return ApiResponse::ok($item->fresh());
    }

    /** Baja lógica. */
    public function destroy(string $id): JsonResponse
    {
        EntregaItem::findOrFail($id)->update(['isActive' => false]);

        return ApiResponse::ok(['message' => 'Ítem de entrega desactivado.']);
    }
}
