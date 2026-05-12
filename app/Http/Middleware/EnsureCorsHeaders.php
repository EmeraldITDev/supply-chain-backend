<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure CORS headers are always present in responses
 * This acts as a safety net to ensure CORS headers are added to every response,
 * including error responses that might bypass the normal CORS middleware.
 *
 * CRITICAL: This must execute AFTER the Laravel HandleCors middleware to avoid duplicate processing
 */
class EnsureCorsHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */

    public function handle(Request $request, Closure $next): Response
    {
        // Force Laravel to parse multipart body if not already parsed
        if ($request->getMethod() === 'POST' && empty($request->all())) {
            $content = $request->getContent();
            \Log::warning('Request body empty after middleware, content length: ' . strlen($content));
        }

        // Get the origin from the request
        $origin = $request->header('Origin');

        // Get configured allowed origins and patterns
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedPatterns = config('cors.allowed_origins_patterns', []);

        // DEBUG: Log all details
        \Log::info('EnsureCorsHeaders middleware executing', [
            'origin' => $origin,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'allowed_origins' => $allowedOrigins,
        ]);

        // Check if origin is allowed
        $isAllowed = false;

        // Direct origin check
        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $isAllowed = true;
            \Log::info('Origin matched allowed_origins directly', ['origin' => $origin]);
        }

        // Pattern-based origin check
        if (!$isAllowed && $origin) {
            foreach ($allowedPatterns as $pattern) {
                if (preg_match($pattern, $origin)) {
                    $isAllowed = true;
                    \Log::info('Origin matched pattern', ['origin' => $origin, 'pattern' => $pattern]);
                    break;
                }
            }
        }

        if (!$isAllowed) {
            \Log::warning('Origin not allowed', ['origin' => $origin, 'allowed_origins' => $allowedOrigins]);
        }

        // Handle preflight (OPTIONS) requests
        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);

            if ($isAllowed && $origin) {
                $response->headers->set('Access-Control-Allow-Origin', $origin, true);
                $response->headers->set('Access-Control-Allow-Credentials', 'true', true);
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
                $response->headers->set('Access-Control-Allow-Headers', 'Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
                $response->headers->set('Access-Control-Max-Age', '86400', true);
                $response->headers->set('Vary', 'Origin', true);
            }

            return $response;
        }

        // Process the actual request
        $response = $next($request);

        // Always add CORS headers if origin is allowed
        if ($isAllowed && $origin) {
            \Log::info('Adding CORS headers to response', [
                'origin' => $origin,
                'status' => $response->getStatusCode(),
            ]);
            $response->headers->set('Access-Control-Allow-Origin', $origin, true);
            $response->headers->set('Access-Control-Allow-Credentials', 'true', true);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
            $response->headers->set('Access-Control-Max-Age', '86400', true);
            $response->headers->set('Vary', 'Origin', true);
        } else {
            \Log::warning('NOT adding CORS headers - conditions not met', [
                'isAllowed' => $isAllowed,
                'hasOrigin' => !empty($origin),
                'origin' => $origin,
            ]);
        }

        return $response;
    }
}
