<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\EntregaItemController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\TipoRegistroController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Jefe\DashboardController;
use App\Http\Controllers\Jefe\ReportController;
use App\Http\Controllers\Jefe\TemplateController;
use App\Http\Controllers\TotalController;
use App\Http\Controllers\TramiteRegistroController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/register', [AuthController::class, 'register'])->middleware(['auth:sanctum', 'role:ADMIN']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
    Route::post('/reset-password/{id}', [AuthController::class, 'resetPassword'])->middleware(['auth:sanctum', 'role:ADMIN']);
    Route::get('/sessions', [AuthController::class, 'sessions'])->middleware('auth:sanctum');
    Route::delete('/sessions/others', [AuthController::class, 'destroyOtherSessions'])->middleware('auth:sanctum');
    Route::delete('/sessions/{id}', [AuthController::class, 'destroySession'])->middleware('auth:sanctum');
});

Route::prefix('users')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::patch('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
});

Route::prefix('services')->group(function () {
    Route::get('/type/{type}', [ServiceController::class, 'byType'])
        ->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO|OPERATOR_ENTREGA']);
    Route::prefix('admin')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
        Route::get('/', [ServiceController::class, 'index']);
        Route::post('/', [ServiceController::class, 'store']);
        Route::get('/{id}', [ServiceController::class, 'show']);
        Route::patch('/{id}', [ServiceController::class, 'update']);
        Route::delete('/{id}', [ServiceController::class, 'destroy']);
        Route::put('/{id}/entrega-items', [ServiceController::class, 'updateEntregaItems']);
    });
});

Route::get('/tipos-registro', [TipoRegistroController::class, 'active'])
    ->middleware(['auth:sanctum', 'role:ADMIN|JEFE|OPERATOR_INGRESO|OPERATOR_ENTREGA']);

Route::prefix('tipos-registro/admin')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('/', [TipoRegistroController::class, 'index']);
    Route::post('/', [TipoRegistroController::class, 'store']);
    Route::get('/{id}', [TipoRegistroController::class, 'show']);
    Route::patch('/{id}', [TipoRegistroController::class, 'update']);
    Route::delete('/{id}', [TipoRegistroController::class, 'destroy']);
});

Route::get('/entrega-items', [EntregaItemController::class, 'active'])
    ->middleware(['auth:sanctum', 'role:ADMIN|JEFE|OPERATOR_INGRESO|OPERATOR_ENTREGA']);

Route::prefix('entrega-items/admin')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('/', [EntregaItemController::class, 'index']);
    Route::post('/', [EntregaItemController::class, 'store']);
    Route::get('/{id}', [EntregaItemController::class, 'show']);
    Route::patch('/{id}', [EntregaItemController::class, 'update']);
    Route::delete('/{id}', [EntregaItemController::class, 'destroy']);
});

Route::prefix('tramites')
    ->middleware(['auth:sanctum', 'role:ADMIN|JEFE|OPERATOR_INGRESO|OPERATOR_ENTREGA'])
    ->group(function () {
        Route::get('/catalog', [TramiteRegistroController::class, 'catalog']);
        Route::get('/export/pdf', [TramiteRegistroController::class, 'exportPdf']);
        Route::get('/export/excel', [TramiteRegistroController::class, 'exportExcel']);
        Route::get('/', [TramiteRegistroController::class, 'index']);
        Route::post('/', [TramiteRegistroController::class, 'store']);
        Route::patch('/{id}', [TramiteRegistroController::class, 'update']);
        Route::delete('/{id}', [TramiteRegistroController::class, 'destroy']);
    });

Route::prefix('totals')->group(function () {
    Route::get('/summary/day', [TotalController::class, 'day'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/week', [TotalController::class, 'week'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/range', [TotalController::class, 'range'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/daily', [TotalController::class, 'daily'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
});

Route::prefix('backups')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('/', [BackupController::class, 'index']);
    Route::post('/', [BackupController::class, 'store']);
    Route::post('/config', [BackupController::class, 'updateConfig']);
    Route::get('/directories', [BackupController::class, 'directories']);
    Route::post('/directories', [BackupController::class, 'createDirectory']);
    Route::get('/download', [BackupController::class, 'download']);
    Route::post('/restore', [BackupController::class, 'restore']);
    Route::post('/restore-upload', [BackupController::class, 'restoreUpload']);
});

Route::prefix('templates')->middleware(['auth:sanctum', 'role:ADMIN|JEFE'])->group(function () {
    Route::get('/', [TemplateController::class, 'index']);
    Route::get('/default', [TemplateController::class, 'default']);
    Route::post('/', [TemplateController::class, 'store']);
    Route::get('/{id}', [TemplateController::class, 'show']);
    Route::patch('/{id}/default', [TemplateController::class, 'setDefault']);
    Route::delete('/{id}', [TemplateController::class, 'destroy']);
    Route::get('/{id}/download', [TemplateController::class, 'download']);
    Route::post('/{id}/duplicate', [TemplateController::class, 'duplicate']);
    Route::get('/{id}/inspect', [TemplateController::class, 'inspect']);
    Route::get('/{id}/paragraphs', [TemplateController::class, 'paragraphs']);
    Route::patch('/{id}/paragraphs', [TemplateController::class, 'updateParagraphs']);
    Route::post('/{id}/paragraphs', [TemplateController::class, 'appendParagraph']);
});

Route::prefix('reports')->middleware(['auth:sanctum', 'role:ADMIN|JEFE'])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/weekly', [DashboardController::class, 'weekly']);
    Route::get('/dashboard/range', [DashboardController::class, 'range']);
    Route::get('/dashboard/daily', [DashboardController::class, 'daily']);
    Route::post('/preview', [ReportController::class, 'preview']);
    Route::post('/generate', [ReportController::class, 'generate']);
    Route::get('/', [ReportController::class, 'index']);
    Route::get('/{id}/download', [ReportController::class, 'download']);
    Route::get('/{id}/pdf', [ReportController::class, 'pdf']);
    Route::delete('/{id}', [ReportController::class, 'destroy']);
});
