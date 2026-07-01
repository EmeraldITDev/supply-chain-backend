<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppConfigController extends Controller
{
    public function financeRouting(Request $request, FinanceRoutingService $routing): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $cutover = $routing->cutoverDate();

        return response()->json([
            'success' => true,
            'data' => [
                'cutoverDate' => $cutover?->toDateString(),
                'routingConfigured' => $routing->isRoutingConfigured(),
                'description' => $cutover
                    ? 'MRFs created on or after '.$cutover->toDateString().' use Finance AP routing.'
                    : 'FINANCE_AP_CUTOVER_DATE is not set on the server. Configure it in the backend environment.',
            ],
        ]);
    }
}
