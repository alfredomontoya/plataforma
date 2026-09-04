<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Envelope único {success:false,error} para /api/* (contrato 04)
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
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
                || $e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return ApiResponse::error('Sin permiso para esta acción.', 403);
            }
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return ApiResponse::error('Recurso no encontrado.', 404);
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
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
