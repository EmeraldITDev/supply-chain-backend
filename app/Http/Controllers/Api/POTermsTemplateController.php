<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\POTermsTemplate;
use Illuminate\Http\Request;

class POTermsTemplateController extends Controller
{
    /**
     * Built-in fallback terms used when no seeded template is present yet.
     * Ensures the "Send RFQ to Vendors" dialog never breaks even on a fresh
     * environment that hasn't run `db:seed --class=POTermsTemplateSeeder`.
     */
    private const FALLBACK_TEMPLATES = [
        'goods' => "Standard terms:\n- Deliver only brand-new and compliant goods.\n- Package contents must be clearly identified and accompanied by delivery documents.\n- Replace non-conforming goods at no additional cost.",
        'services' => "Standard terms:\n- Perform services in line with approved scope and timelines.\n- Submit progress evidence with invoice.\n- Rework non-conforming deliverables at no additional cost.",
        'logistics' => "Standard terms:\n- Adhere to agreed pickup and delivery windows.\n- Provide transport documentation and incident reports where applicable.\n- Maintain cargo integrity and compliance throughout transit.",
        'rfq' => "Standard RFQ terms:\n- Submit detailed line-item pricing in the quoted currency.\n- State validity window of the quotation (calendar days from issue date).\n- Confirm lead time, payment terms and applicable warranty.\n- Disclose any deviations from the requested specifications.\n- Quotations submitted after the stated deadline will not be considered.",
    ];

    public function show(Request $request, string $type)
    {
        $user = $request->user();
        // Standard PO/RFQ terms are needed by anyone who can author or review
        // an RFQ/PO (procurement, supply chain director, logistics, finance, admin).
        // Restricting to procurement-only blocked the new logistics SRF flow.
        $allowedRoles = [
            'procurement_manager',
            'procurement',
            'supply_chain_director',
            'supply_chain',
            'logistics_manager',
            'logistics_officer',
            'finance',
            'finance_officer',
            'executive',
            'admin',
        ];
        if (!$user || !in_array($user->role, $allowedRoles, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $normalisedType = strtolower(trim($type));

        $template = POTermsTemplate::query()
            ->where('po_type', $normalisedType)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (!$template && isset(self::FALLBACK_TEMPLATES[$normalisedType])) {
            // Lazily seed the missing template so subsequent reads hit the DB.
            $template = POTermsTemplate::create([
                'po_type' => $normalisedType,
                'content' => self::FALLBACK_TEMPLATES[$normalisedType],
                'is_active' => true,
            ]);
        }

        if (!$template) {
            return response()->json([
                'success' => false,
                'error' => 'PO terms template not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'po_type' => $template->po_type,
                'content' => $template->content,
                'is_active' => (bool) $template->is_active,
            ],
        ]);
    }
}
