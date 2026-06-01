<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceIntegrationService;
use Illuminate\Http\Request;

class FinanceApWebhookController extends Controller
{
    public function __construct(
        private FinanceIntegrationService $financeIntegration,
    ) {
    }

    public function handle(Request $request)
    {
        $signature = $request->header(config('finance_ap.webhook.signature_header', 'X-Finance-Ap-Signature'));
        $payload = $request->all();
        $rawBody = $request->getContent();

        if ($rawBody === '' && $payload !== []) {
            $rawBody = json_encode($payload);
        }

        $result = $this->financeIntegration->handleWebhook($payload, $signature, $rawBody);

        if (! ($result['success'] ?? false)) {
            $code = $result['code'] ?? 'WEBHOOK_ERROR';
            $status = match ($code) {
                'INVALID_SIGNATURE' => 401,
                'NOT_FOUND' => 404,
                'VALIDATION_ERROR' => 422,
                default => 400,
            };

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Webhook processing failed',
                'code' => $code,
            ], $status);
        }

        return response()->json([
            'success' => true,
            'duplicate' => $result['duplicate'] ?? false,
            'data' => $result['data'] ?? null,
        ]);
    }
}
