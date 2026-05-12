<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\POTermsTemplate;
use Illuminate\Http\Request;

class POTermsTemplateController extends Controller
{
    /**
     * @return array<string, string>
     */
    private function fallbackTemplates(): array
    {
        return array_filter(
            config('po_terms_templates', []),
            static fn ($body) => is_string($body) && $body !== ''
        );
    }

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
        if (! $user || ! in_array($user->role, $allowedRoles, true)) {
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

        $fallbacks = $this->fallbackTemplates();

        if (! $template && isset($fallbacks[$normalisedType])) {
            // Lazily seed the missing template so subsequent reads hit the DB.
            $template = POTermsTemplate::create([
                'po_type' => $normalisedType,
                'content' => $fallbacks[$normalisedType],
                'is_active' => true,
            ]);
        }

        if (! $template) {
            return response()->json([
                'success' => false,
                'error' => 'PO terms template not found',
                'code' => 'NOT_FOUND',
            ], 404);
        }

        $body = (string) $template->content;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'type' => $template->po_type,
                'po_type' => $template->po_type,
                'content' => $body,
                'standard_terms' => $body,
                'is_active' => (bool) $template->is_active,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
