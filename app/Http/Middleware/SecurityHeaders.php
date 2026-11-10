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

        // HSTS desactivado durante el despliegue interno: los equipos cliente
        // aún no tienen instalada la CA local y HSTS (por host, no por puerto)
        // rompería los enlaces antiguos a http://splaadm23p-10:8084. Se puede
        // reactivar cuando todos usen https://splaadm23p-10 sin puerto.
        // if ($request->isSecure()) {
        //     $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        // }

        if (app()->environment('production')) {
            $csp = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
                ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'";
            $response->headers->set('Content-Security-Policy-Report-Only', $csp);
        }

        return $response;
    }
}
