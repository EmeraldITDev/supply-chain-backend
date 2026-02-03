<?php

namespace App\Services\Logistics;

use App\Models\Logistics\IdempotencyKey;
use Illuminate\Http\Request;

class IdempotencyService
{
    public function getCachedResponse(Request $request): ?array
    {
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return null;
        }

        $record = IdempotencyKey::where('key', $key)->first();

        if (!$record) {
            return null;
        }

        return [
            'status' => $record->status_code,
            'response' => $record->response,
        ];
    }

    public function storeResponse(Request $request, array $response, int $statusCode): void
    {
        $key = $request->header('Idempotency-Key');

        if (!$key) {
            return;
        }

        IdempotencyKey::firstOrCreate([
            'key' => $key,
        ], [
            'user_id' => $request->user()?->id,
            'route' => $request->path(),
            'response' => $response,
            'status_code' => $statusCode,
        ]);
    }
}
