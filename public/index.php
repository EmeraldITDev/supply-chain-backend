<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Last-resort CORS safety net. If PHP dies mid-request (fatal error,
// `post_max_size` exceeded, OOM, max_execution_time, etc.) Laravel's
// middleware never runs, so the browser receives a 200/500 with no
// Access-Control-Allow-Origin header and reports it as a CORS error.
// This shutdown handler guarantees the headers are emitted before the
// connection closes, so the frontend can read whatever PHP managed to
// produce.
register_shutdown_function(static function (): void {
    if (headers_sent()) {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowedOrigins = [
        'http://localhost:3000',
        'http://localhost:5173',
        'http://localhost:8080',
        'http://localhost:8081',
        'https://emerald-supply-chain.vercel.app',
        'https://scm.emeraldcfze.com',
        'https://supply-chain-backend-hwh6.onrender.com',
    ];

    $envOrigins = getenv('CORS_ALLOWED_ORIGINS');
    if (is_string($envOrigins) && $envOrigins !== '') {
        foreach (explode(',', $envOrigins) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $allowedOrigins[] = $entry;
            }
        }
    }

    $allowedPatterns = [
        '#^https://.*\.lovable\.app$#',
        '#^https://.*\.vercel\.app$#',
        '#^https://.*\.emeraldcfze\.com$#',
    ];

    $isAllowed = in_array($origin, $allowedOrigins, true);
    if (!$isAllowed) {
        foreach ($allowedPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                $isAllowed = true;
                break;
            }
        }
    }

    if (!$isAllowed) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin, true);
    header('Access-Control-Allow-Credentials: true', true);
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
    header('Access-Control-Allow-Headers: Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
    header('Vary: Origin', false);

    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json', true);
            echo json_encode([
                'success' => false,
                'error' => 'A fatal server error occurred while processing the request.',
                'code' => 'FATAL_ERROR',
            ]);
        }
    }
});

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
