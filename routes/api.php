<?php

use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\Jefe\DashboardController;
use App\Http\Controllers\Jefe\ReportController;
use App\Http\Controllers\Jefe\TemplateController;
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
    Route::get('/{id}', [UserController::class, 'show']);
    Route::patch('/{id}', [UserController::class, 'update']);
    Route::patch('/{id}/position', [UserController::class, 'updatePosition']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::post('/{id}/reset-password', [UserController::class, 'resetPassword']);
});

Route::prefix('services')->group(function () {
    Route::get('/type/{type}', [ServiceController::class, 'byType'])->middleware('auth:sanctum');
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

Route::prefix('templates')->middleware(['auth:sanctum', 'role:ADMIN|JEFE'])->group(function () {
    Route::get('/', [TemplateController::class, 'index']);
    Route::get('/default', [TemplateController::class, 'default']);
    Route::post('/', [TemplateController::class, 'store']);
    Route::get('/{id}', [TemplateController::class, 'show']);
    Route::patch('/{id}/default', [TemplateController::class, 'setDefault']);
    Route::delete('/{id}', [TemplateController::class, 'destroy']);
});

Route::prefix('reports')->middleware(['auth:sanctum', 'role:ADMIN|JEFE'])->group(function () {
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/weekly', [DashboardController::class, 'weekly']);
    Route::get('/dashboard/range', [DashboardController::class, 'range']);
    Route::post('/preview', [ReportController::class, 'preview']);
    Route::post('/generate', [ReportController::class, 'generate']);
    Route::get('/', [ReportController::class, 'index']);
    Route::get('/{id}/download', [ReportController::class, 'download']);
    Route::delete('/{id}', [ReportController::class, 'destroy']);
});
