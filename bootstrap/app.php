<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // (A7) Rate limiting global para /api/*: 120 peticiones por minuto por clave.
        $middleware->throttleApi('120,1');
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);
        // (SEC-003/SEC-005) isActive por petición y cabeceras de seguridad en API.
        $middleware->api(append: [
            EnsureUserIsActive::class,
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope único {success:false,error} para /api/* (contrato 04)
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    $e->validator->errors()->first() ?: 'Datos inválidos.',
                    400,
                    $e->errors()
                );
            }
            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('No autenticado.', 401);
            }
            if ($e instanceof UnauthorizedException
                || $e instanceof AuthorizationException) {
                return ApiResponse::error('Sin permiso para esta acción.', 403);
            }
            if ($e instanceof ModelNotFoundException
                || $e instanceof NotFoundHttpException) {
                return ApiResponse::error('Recurso no encontrado.', 404);
            }
            if ($e instanceof TooManyRequestsHttpException) {
                return ApiResponse::error('Demasiados intentos. Intente más tarde.', 429);
            }
            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return ApiResponse::error($e->getMessage() ?: 'Error de solicitud.', $status);
            }

            $message = config('app.debug') ? $e->getMessage() : 'Error interno del servidor.';

            return ApiResponse::error($message, 500);
        });
    })->create();
