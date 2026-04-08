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

    'paths' => ['api/*', 'sanctum/csrf-cookie'], // Handle all paths since CORS middleware is global

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        [
            'http://localhost:8081',
            'http://localhost:8080',
            'http://localhost:3000',
            'http://localhost:5173',
            'https://emerald-supply-chain.vercel.app',
            'https://scm.emeraldcfze.com',
        ],
        env('CORS_ALLOWED_ORIGINS') ? explode(',', env('CORS_ALLOWED_ORIGINS')) : []
    ))))),

    'allowed_origins_patterns' => [
        '#^https://.*\.lovable\.app$#', // Allow all Lovable preview domains
        '#^https://.*\.vercel\.app$#', // Allow all Vercel preview deployments
        '#^https://.*\.emeraldcfze\.com$#', // Allow all emeraldcfze.com subdomains
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // 24 hours - allows browsers to cache preflight requests

    'supports_credentials' => true,

];
