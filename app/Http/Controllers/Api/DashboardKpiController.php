<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MRF;
use App\Models\PriceComparison;
use App\Models\SRF;
use App\Services\DashboardStatsCache;
use Illuminate\Http\Request;

class DashboardKpiController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Unauthenticated'], 401);
        }

        $kpis = DashboardStatsCache::remember('dashboard.kpis', function () {
            $posGenerated = MRF::whereNotNull('po_number')->count();
            $mrfsApproved = MRF::where(function ($q): void {
                $q->where('executive_approved', true)
                    ->orWhereNotNull('director_approved_at')
                    ->orWhereIn('workflow_state', ['procurement_review', 'vendor_selection', 'po_generation', 'po_signed', 'closed']);
            })->count();
            $srfsApproved = SRF::where('status', 'Approved')->count();
            $priceComparisonCount = PriceComparison::query()
                ->distinct('purchase_order_id')
                ->count('purchase_order_id');

            return [
                'totalPosGenerated' => $posGenerated,
                'totalMrfsApproved' => $mrfsApproved,
                'totalSrfsApproved' => $srfsApproved,
                'priceComparisonCount' => $priceComparisonCount,
            ];
        });

        return response()->json([
            'success' => true,
            'kpis' => $kpis,
        ]);
    }
}
