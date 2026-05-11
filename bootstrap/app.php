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
        // CRITICAL: Apply CORS to all requests - MUST be absolutely first before any other middleware
        // Prepend happens in reverse order, so we prepend EnsureCorsHeaders first (to be second),
        // then HandleCors (to be first), then TrackUserActivity gets added normally
        $middleware->prepend(\App\Http\Middleware\EnsureCorsHeaders::class);
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Track user activity for automatic logout after 5 minutes of inactivity
        // This is NOT prepended so it comes after CORS middleware
        $middleware->use([
            \App\Http\Middleware\TrackUserActivity::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
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

            // Handle connection timeout errors
            if (str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'Connection timeout') ||
                str_contains($e->getMessage(), 'Lost connection') ||
                $e instanceof \Illuminate\Database\QueryException) {

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
