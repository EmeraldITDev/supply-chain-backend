<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ContractTypeHelper;
use Illuminate\Http\JsonResponse;

class ContractTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'standardTypes' => ContractTypeHelper::standardOptions(),
            'allowFreeText' => true,
            'routingNote' => 'Non-standard contract types are routed directly to the Supply Chain Director.',
        ]);
    }
}
