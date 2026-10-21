<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/**
 * (Bajo) Defensa en profundidad — decisión documentada:
 * El frontend es una SPA que se autentica exclusivamente con un token Bearer
 * guardado en localStorage (ver resources/js/lib/auth.jsx: lee/escribe
 * `accessToken` y valida contra `GET /api/auth/me`). NO existe login por sesión
 * web: `HandleInertiaRequests` no comparte usuario y no hay guard `web` activo
 * en el flujo. Por tanto, proteger estas rutas con el middleware `auth` (sesión)
 * redirigiría permanentemente a /login a los usuarios que solo tienen token,
 * rompiendo la app. La protección real es: datos siempre vía API bajo
 * `auth:sanctum` (+ `role:*`), y control de acceso en cliente con RoleGuard.
 * Se documenta aquí en lugar de aplicar `auth` para no romper el login.
 */

Route::get('/login', fn () => Inertia::render('Login'));

Route::get('/', fn () => redirect('/operador/entrada'));
Route::get('/operador/entrada', fn () => Inertia::render('Entry'));
Route::get('/operador/total', fn () => Inertia::render('TotalEntry'));
Route::get('/admin/usuarios', fn () => Inertia::render('Users'));
Route::get('/admin/rrhh', fn () => Inertia::render('RRHH'));
Route::get('/admin/servicios', fn () => Inertia::render('Services'));
Route::get('/admin/backups', fn () => Inertia::render('Backups'));
Route::get('/jefe/dashboard', fn () => Inertia::render('Dashboard'));
Route::get('/jefe/reportes', fn () => Inertia::render('Reports'));
Route::get('/jefe/plantillas', fn () => Inertia::render('Templates'));
Route::get('/jefe/reportes/historial', fn () => Inertia::render('History'));
Route::get('/acerca-de', fn () => Inertia::render('About'));
