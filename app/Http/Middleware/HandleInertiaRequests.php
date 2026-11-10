<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            // (SEC-008) Entorno visible en sidebar/login sin exponer el nombre
            // de la base de datos ni otros detalles internos al navegador.
            'env' => [
                'name' => app()->environment(),
                'isProduction' => app()->environment('production'),
            ],
        ]);
    }
}
