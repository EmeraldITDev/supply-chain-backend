<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Render / Cloudflare / Vercel, PHP must trust forwarded proto/host so the request
        // is treated as HTTPS and cookies / Sanctum state line up with the browser.
        // Prepend order: last prepend runs outermost (first on the request) — TrustProxies must be last here.
        $middleware->trustProxies(at: '*');

        // CRITICAL: Apply CORS to all requests - MUST be absolutely first before any other middleware
        // Prepend happens in reverse order, so we prepend EnsureCorsHeaders first (to be second),
        // then HandleCors (to be first), then TrackUserActivity gets added normally
        $middleware->prepend(\App\Http\Middleware\EnsureCorsHeaders::class);
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);
        $middleware->prepend(\Illuminate\Http\Middleware\TrustProxies::class);

        // Track user activity for automatic logout after 5 minutes of inactivity
        // This is NOT prepended so it comes after CORS middleware
        $middleware->use([
            \App\Http\Middleware\TrackUserActivity::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'finance_ap.integration' => \App\Http\Middleware\VerifyFinanceApIntegrationKey::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\CompressJsonResponse::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withSchedule(function ($schedule) {
        // Run document expiry check daily at midnight
        $schedule->command('documents:mark-expired')->dailyAt('00:00')
            ->name('Update Expired Vendor Documents')
            ->description('Mark vendor registration documents as expired');

        $schedule->command('fleet:check-vehicle-documents')->dailyAt('00:05')
            ->withoutOverlapping(10);

        $schedule->command('fleet:check-maintenance')->dailyAt('00:10')
            ->withoutOverlapping(10);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON responses for API errors
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->expectsJson() || str_starts_with($request->path(), 'api/');
        });

        // Handle all exceptions and ensure CORS headers are added
        $exceptions->render(function (\Throwable $e, $request) {
            // Helper function to add CORS headers to response
            $addCorsHeaders = function ($response, $origin) {
                if ($origin) {
                    $allowedOrigins = config('cors.allowed_origins', []);
                    $allowedPatterns = config('cors.allowed_origins_patterns', []);

                    $isAllowed = in_array($origin, $allowedOrigins, true);

                    if (!$isAllowed) {
                        foreach ($allowedPatterns as $pattern) {
                            if (preg_match($pattern, $origin)) {
                                $isAllowed = true;
                                break;
                            }
                        }
                    }

                    if ($isAllowed) {
                        $response->headers->set('Access-Control-Allow-Origin', $origin, true);
                        $response->headers->set('Access-Control-Allow-Credentials', 'true', true);
                        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
                        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
                        $response->headers->set('Access-Control-Max-Age', '86400', true);
                    }
                }
                return $response;
            };

            $origin = $request->header('Origin');

            // Handle connection-level database failures only. We intentionally do
            // not blanket-treat every QueryException as 503; a constraint or
            // schema bug should surface as a 500 so the frontend doesn't
            // tell the user "try again later" when retrying cannot help.
            $message = $e->getMessage();
            $isConnectionError = str_contains($message, 'Connection refused')
                || str_contains($message, 'Connection timeout')
                || str_contains($message, 'Lost connection')
                || str_contains($message, 'server has gone away')
                || str_contains($message, 'No such host is known')
                || str_contains($message, 'could not translate host name')
                || ($e instanceof \PDOException && in_array($e->getCode(), ['2002', '2003', '2006', '2013', '08006', '08001', '08004', 'HY000'], true));

            if ($isConnectionError) {
                $statusCode = 503;

                $response = response()->json([
                    'success' => false,
                    'error' => 'Database connection error',
                    'code' => 'CONNECTION_ERROR',
                    'message' => 'The server is unable to process your request. Please try again in a few moments.',
                    'timestamp' => now()->toIso8601String(),
                ], $statusCode)->header('Connection', 'close');

                return $addCorsHeaders($response, $origin);
            }

            // Generic query exception (constraint violation, missing column,
            // etc.) — surface as a 500 with useful debug payload but never
            // leak the SQL in production.
            if ($e instanceof \Illuminate\Database\QueryException) {
                \Log::error('Unhandled query exception', [
                    'message' => $message,
                    'sql' => $e->getSql() ?? null,
                    'bindings' => $e->getBindings() ?? [],
                    'path' => $request->path(),
                ]);

                $response = response()->json([
                    'success' => false,
                    'error' => 'A database error occurred while processing the request.',
                    'code' => 'DATABASE_ERROR',
                    'message' => config('app.debug') ? $message : 'Please try again or contact support if the problem persists.',
                    'timestamp' => now()->toIso8601String(),
                ], 500);

                return $addCorsHeaders($response, $origin);
            }

            // Handle validation errors
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $response = response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                    'timestamp' => now()->toIso8601String(),
                ], 422);

                return $addCorsHeaders($response, $origin);
            }

            // Handle authentication errors
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $response = response()->json([
                    'success' => false,
                    'error' => 'Unauthenticated',
                    'code' => 'UNAUTHENTICATED',
                    'timestamp' => now()->toIso8601String(),
                ], 401);

                return $addCorsHeaders($response, $origin);
            }

            // Handle authorization errors
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $response = response()->json([
                    'success' => false,
                    'error' => 'Forbidden',
                    'code' => 'FORBIDDEN',
                    'timestamp' => now()->toIso8601String(),
                ], 403);

                return $addCorsHeaders($response, $origin);
            }

            // Return null to let Laravel handle it with default behavior
            return null;
        });
    })->create();
