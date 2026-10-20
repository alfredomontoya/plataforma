<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rutas con CORS
    |--------------------------------------------------------------------------
    |
    | Solo la API: el frontend se sirve desde el mismo origen (Inertia), así
    | que no necesita CORS abierto. Evita que cualquier web externa pueda
    | consultar la API desde el navegador de un usuario autenticado.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Orígenes permitidos
    |--------------------------------------------------------------------------
    |
    | Se toma de CORS_ALLOWED_ORIGINS (lista separada por comas). Por defecto
    | solo el propio APP_URL: si el servidor no lo define, ningún origen
    | externo podrá llamar a la API desde el navegador.
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('APP_URL', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    |--------------------------------------------------------------------------
    | Credenciales
    |--------------------------------------------------------------------------
    |
    | La app usa Bearer token (no cookies) para /api/*, así que no se envían
    | credenciales de navegador entre orígenes.
    |
    */

    'supports_credentials' => false,

];
