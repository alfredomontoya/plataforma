<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Envelope único de la API (contrato 04):
 * éxito `{success:true,data}` · listas `{data,total,page,limit,totalPages}` ·
 * error `{success:false,error}` (+ `details` opcional).
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public static function created(mixed $data = null): JsonResponse
    {
        return self::ok($data, 201);
    }

    public static function paginated(LengthAwarePaginator $paginator, mixed $extra = []): JsonResponse
    {
        $payload = array_merge([
            'data' => $paginator->values(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'limit' => $paginator->perPage(),
            'totalPages' => $paginator->lastPage(),
        ], $extra);

        return response()->json($payload);
    }

    public static function error(string $message, int $status = 400, mixed $details = null): JsonResponse
    {
        $payload = ['success' => false, 'error' => $message];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status);
    }
}
