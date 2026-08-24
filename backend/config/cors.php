<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL es el SPA del sistema (mismo origen en producción; distinto solo en desarrollo
    // local). LANDING_URL es la landing pública en otro dominio (ver 037-landing-prosello.md), que
    // solo llama a POST /api/v1/contacto sin sesión ni cookies —su fetch no manda credenciales—.
    // 'supports_credentials' sigue en true por el SPA, pero SESSION_SAME_SITE=lax (config/session.php)
    // ya impide que el navegador adjunte la cookie de sesión en una petición cross-site como esta,
    // así que agregar este origen no abre una vía nueva hacia las rutas autenticadas.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('LANDING_URL'),
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
