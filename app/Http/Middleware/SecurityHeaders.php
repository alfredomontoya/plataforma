<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * (SEC-005) Cabeceras de seguridad comunes. La CSP se envía en modo
 * Report-Only para no romper recursos externos hasta validarla en producción.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // HSTS desactivado en este host: splaadm23p-10 se comparte con apps
        // HTTP en los puertos 80, 8080 y 8082. HSTS ignora el puerto y forzaría
        // HTTPS en todas ellas (ERR_SSL_PROTOCOL_ERROR). Se emite max-age=0
        // para que los navegadores olviden la política previa de 1 año.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=0');
        }

        if (app()->environment('production')) {
            $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
                ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
            $response->headers->set('Content-Security-Policy-Report-Only', $csp);
        }

        return $response;
    }
}
