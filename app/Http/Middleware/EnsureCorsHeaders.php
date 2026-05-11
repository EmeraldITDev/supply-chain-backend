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
        // Get the origin from the request
        $origin = $request->header('Origin');

        // Get configured allowed origins and patterns
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedPatterns = config('cors.allowed_origins_patterns', []);

        // Check if origin is allowed
        $isAllowed = false;

        // Direct origin check
        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $isAllowed = true;
        }

        // Pattern-based origin check
        if (!$isAllowed && $origin) {
            foreach ($allowedPatterns as $pattern) {
                if (preg_match($pattern, $origin)) {
                    $isAllowed = true;
                    break;
                }
            }
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
            $response->headers->set('Access-Control-Allow-Origin', $origin, true);
            $response->headers->set('Access-Control-Allow-Credentials', 'true', true);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
            $response->headers->set('Access-Control-Max-Age', '86400', true);
            $response->headers->set('Vary', 'Origin', true);
        }

        return $response;
    }
}
