<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\Rrhh\AreaController;
use App\Http\Controllers\Admin\Rrhh\EmpleadoController;
use App\Http\Controllers\Admin\Rrhh\PuestoController;
use App\Http\Controllers\Admin\Rrhh\TratamientoController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\Jefe\DashboardController;
use App\Http\Controllers\Jefe\ReportController;
use App\Http\Controllers\Jefe\TemplateController;
use App\Http\Controllers\TotalController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/register', [AuthController::class, 'register'])->middleware(['auth:sanctum', 'role:ADMIN']);
    Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth:sanctum');
    Route::post('/reset-password/{id}', [AuthController::class, 'resetPassword'])->middleware(['auth:sanctum', 'role:ADMIN']);
});

Route::prefix('users')->middleware(['auth:sanctum', 'role:ADMIN'])->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    // Debe declararse antes de /{id} para no interpretarse como un ID.
    Route::get('/suggest-username', [UserController::class, 'suggestCredentials']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::patch('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
});

// RRHH: áreas, puestos, empleados y tratamientos.
// Lectura para ADMIN/JEFE (reportes y filtros); escritura solo ADMIN.
Route::prefix('rrhh')->middleware('auth:sanctum')->group(function () {
    Route::middleware('role:ADMIN|JEFE')->group(function () {
        Route::get('/tratamientos', [TratamientoController::class, 'index']);
        Route::get('/areas', [AreaController::class, 'index']);
        Route::get('/areas/{id}', [AreaController::class, 'show']);
        Route::get('/puestos', [PuestoController::class, 'index']);
        Route::get('/puestos/{id}', [PuestoController::class, 'show']);
        Route::get('/empleados', [EmpleadoController::class, 'index']);
        Route::get('/empleados/{id}', [EmpleadoController::class, 'show']);
        Route::get('/empleados/{id}/historial', [EmpleadoController::class, 'historial']);
    });

    Route::middleware('role:ADMIN')->group(function () {
        Route::post('/tratamientos', [TratamientoController::class, 'store']);
        Route::patch('/tratamientos/{id}', [TratamientoController::class, 'update']);
        Route::delete('/tratamientos/{id}', [TratamientoController::class, 'destroy']);

        Route::post('/areas', [AreaController::class, 'store']);
        Route::patch('/areas/{id}', [AreaController::class, 'update']);
        Route::delete('/areas/{id}', [AreaController::class, 'destroy']);

        Route::post('/puestos', [PuestoController::class, 'store']);
        Route::patch('/puestos/{id}', [PuestoController::class, 'update']);
        Route::delete('/puestos/{id}', [PuestoController::class, 'destroy']);

        Route::post('/empleados', [EmpleadoController::class, 'store']);
        Route::patch('/empleados/{id}', [EmpleadoController::class, 'update']);
        Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy']);
        Route::post('/empleados/{id}/puestos', [EmpleadoController::class, 'assignPuesto']);
        Route::patch('/empleados/{id}/puestos/{asignacionId}', [EmpleadoController::class, 'closePuesto']);
    });
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
    });
});

Route::prefix('entries')->group(function () {
    Route::post('/', [EntryController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO|OPERATOR_ENTREGA']);
    Route::patch('/{id}', [EntryController::class, 'update'])
        ->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO|OPERATOR_ENTREGA']);
    Route::get('/me', [EntryController::class, 'me'])->middleware('auth:sanctum');
    Route::get('/', [EntryController::class, 'index'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/daily', [EntryController::class, 'summaryDaily'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/by-operator', [EntryController::class, 'summaryByOperator'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
    Route::get('/summary/weekly', [EntryController::class, 'summaryWeekly'])->middleware(['auth:sanctum', 'role:ADMIN|JEFE']);
});

Route::prefix('totals')->group(function () {
    Route::get('/catalog', [TotalController::class, 'catalog'])->middleware('auth:sanctum');
    Route::post('/resolve', [TotalController::class, 'resolve'])
        ->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO']);
    Route::get('/operators', [TotalController::class, 'operators'])->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO']);
    Route::post('/', [TotalController::class, 'store'])
        ->middleware(['auth:sanctum', 'role:ADMIN|OPERATOR_INGRESO']);
    Route::get('/me', [TotalController::class, 'me'])->middleware('auth:sanctum');
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
