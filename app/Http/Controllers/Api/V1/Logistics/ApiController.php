<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Controllers\Controller;

class ApiController extends Controller
{
    protected function success(array $data = [], int $status = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
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
