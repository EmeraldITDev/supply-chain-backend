<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPaginatedLists;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceApOpenPurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceApOpenPurchaseOrderController extends Controller
{
    use ResolvesPaginatedLists;

    public function __construct(
        private readonly FinanceApOpenPurchaseOrderService $openPurchaseOrders,
    ) {
    }

    /**
     * GET /api/v1/integrations/scm/vendors/{scm_vendor_id}/open-purchase-orders
     */
    public function index(Request $request, string $scmVendorId): JsonResponse
    {
        if (! is_numeric($scmVendorId) || (int) $scmVendorId <= 0) {
            return response()->json([
                'success' => false,
                'error' => 'scm_vendor_id must be the SCM vendors.id integer primary key, not a display code like V147',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $vendorId = (int) $scmVendorId;
        $perPage = $this->resolvePerPage($request, default: 25, max: 100);
        $page = $this->resolvePage($request);
        $paginator = $this->openPurchaseOrders->paginateForVendor($vendorId, $page, $perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'scm_vendor_id' => $vendorId,
                'purchase_orders' => array_values($paginator->items()),
            ],
            'pagination' => $this->paginationPayload($paginator),
        ]);
    }
}
