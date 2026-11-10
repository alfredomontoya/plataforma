<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * (SEC-003) Un usuario desactivado no debe conservar acceso con un token
 * emitido antes de la desactivación. Sanctum valida el token, no isActive,
 * así que aquí se comprueba en cada petición y se revocan sus tokens.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? $request->user('sanctum');

        if ($user && ! $user->isActive) {
            $user->tokens()->delete();

            return ApiResponse::error('La cuenta está desactivada.', 403);
        }

        return $next($request);
    }
}
