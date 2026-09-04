<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/login', fn () => Inertia::render('Login'));

Route::get('/', fn () => redirect('/operador/entrada'));
Route::get('/operador/entrada', fn () => Inertia::render('Entry'));
Route::get('/admin/usuarios', fn () => Inertia::render('Users'));
Route::get('/admin/servicios', fn () => Inertia::render('Services'));
Route::get('/jefe/dashboard', fn () => Inertia::render('Dashboard'));
Route::get('/jefe/reportes', fn () => Inertia::render('Reports'));
Route::get('/jefe/reportes/historial', fn () => Inertia::render('History'));
