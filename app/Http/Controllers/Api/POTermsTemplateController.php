<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\POTermsTemplate;
use Illuminate\Http\Request;

class POTermsTemplateController extends Controller
{
    public function show(Request $request, string $type)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['procurement_manager', 'procurement', 'admin'])) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN',
            ], 403);
        }

        $template = POTermsTemplate::query()
            ->where('po_type', strtolower($type))
            ->where('is_active', true)
            ->latest('id')
            ->first();

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
