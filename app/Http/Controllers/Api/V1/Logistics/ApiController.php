<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;

class ApiController extends Controller
{
    use ResolvesPaginatedLists;

    protected function success(array $data = [], int $status = 200)
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        foreach ($data as $key => $value) {
            $payload[$key] = $value;
        }

        return response()->json($payload, $status);
    }

    protected function error(string $message, string $code, int $status = 400, array $errors = []): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
            'errors' => $errors,
        ], $status);
    }
}
