<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $connection = (string) config('database.default');

        return array_merge(parent::share($request), [
            // Entorno visible en sidebar, login y página de backups
            'env' => [
                'name' => app()->environment(),
                'isProduction' => app()->environment('production'),
                'db' => config("database.connections.{$connection}.database"),
            ],
        ]);
    }
}
