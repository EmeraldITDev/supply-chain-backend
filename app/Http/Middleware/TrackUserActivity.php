<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     * 
     * Tracks user activity and invalidates tokens after 5 minutes of inactivity.
     * This applies to both session-based and token-based authentication.
     */
    public function handle(Request $request, Closure $next)
    {
        // Get the authenticated user
        $user = $request->user();

        if ($user) {
            // For API token authentication (Sanctum)
            if ($request->bearerToken()) {
                $this->handleTokenActivity($user, $request);
            }
            // For session authentication
            else {
                $this->handleSessionActivity($request);
            }
        }

        return $next($request);
    }

    /**
     * Track activity for token-based (API) authentication
     * 
     * Uses cache to track last activity time per user
     * Token is considered expired if no activity for 5 minutes
     */
    private function handleTokenActivity($user, Request $request)
    {
        $cacheKey = "user_activity_{$user->id}";
        $inactivityTimeout = 5; // 5 minutes
        
        $lastActivity = Cache::get($cacheKey);
        $now = now();

        if ($lastActivity) {
            $lastActivityTime = Carbon::parse($lastActivity);
            $minutesSinceLastActivity = $now->diffInMinutes($lastActivityTime);

            // If more than 5 minutes have passed, invalidate the token
            if ($minutesSinceLastActivity >= $inactivityTimeout) {
                // Revoke the current token
                if ($request->user() && method_exists($request->user(), 'currentAccessToken')) {
                    $currentToken = $request->user()->currentAccessToken();
                    if ($currentToken) {
                        $currentToken->delete();
                    }
                }

                // Clear cache
                Cache::forget($cacheKey);

                // Return 401 Unauthorized - token expired due to inactivity
                return response()->json([
                    'success' => false,
                    'error' => 'Session expired due to inactivity. Please log in again.',
                    'code' => 'SESSION_EXPIRED'
                ], 401);
            }
        }

        // Update last activity time
        Cache::put($cacheKey, $now->toDateTimeString(), now()->addMinutes(6));
    }

    /**
     * Track activity for session-based authentication
     */
    private function handleSessionActivity(Request $request)
    {
        $inactivityTimeout = 5; // 5 minutes in minutes

        // Check if session has last activity time
        if (Session::has('last_activity')) {
            $lastActivity = Session::get('last_activity');
            $lastActivityTime = Carbon::parse($lastActivity);
            $minutesSinceLastActivity = now()->diffInMinutes($lastActivityTime);

            // If more than 5 minutes have passed, logout the user
            if ($minutesSinceLastActivity >= $inactivityTimeout) {
                // Clear session
                Session::flush();

                // Log the timeout
                \Log::info('User session expired due to inactivity', [
                    'user_id' => auth()->id(),
                    'ip' => $request->ip(),
                    'last_activity' => $lastActivity
                ]);

                // Return 401 - session expired
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Session expired due to inactivity. Please log in again.',
                        'code' => 'SESSION_EXPIRED'
                    ], 401);
                }
            }
        }

        // Update last activity time
        Session::put('last_activity', now()->toDateTimeString());
    }
}
