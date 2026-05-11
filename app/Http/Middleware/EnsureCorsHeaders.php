<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure CORS headers are always present in responses
 * This acts as a safety net to ensure CORS headers aren't stripped by other middleware
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
        $response = $next($request);

        $origin = $request->header('Origin');
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedPatterns = config('cors.allowed_origins_patterns', []);

        $isAllowed = false;

        // Check direct allowed origins
        if ($origin && in_array($origin, $allowedOrigins, true)) {
            $isAllowed = true;
        }

        // Check pattern-based allowed origins
        if (!$isAllowed && $origin) {
            foreach ($allowedPatterns as $pattern) {
                if (preg_match($pattern, $origin)) {
                    $isAllowed = true;
                    break;
                }
            }
        }

        // Set CORS headers if origin is allowed
        if ($isAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin, true);
            $response->headers->set('Access-Control-Allow-Credentials', 'true', true);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD', true);
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Accept-Language, Content-Language, Content-Type, Authorization, X-Requested-With, X-CSRF-Token', true);
            $response->headers->set('Access-Control-Max-Age', '86400', true);
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Length, X-JSON-Response-Code', true);
        }

        // Handle preflight requests
        if ($request->getMethod() === 'OPTIONS') {
            return response('', 204)
                ->withHeaders($response->headers->all());
        }

        return $response;
    }
}
