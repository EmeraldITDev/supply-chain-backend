<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SRF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SRFController extends Controller
{
    /**
     * Get all SRFs
     */
    public function index(Request $request)
    {
        $query = SRF::with('requester');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by requester (for employees to see only their own)
        $user = $request->user();
        if ($user && in_array($user->role, ['employee', 'general_employee'])) {
            $query->where('requester_id', $user->id);
        }

        $srfs = $query->orderBy('date', 'desc')->get();

        return response()->json($srfs->map(function($srf) {
            return [
                'id' => $srf->srf_id,
                'title' => $srf->title,
                'serviceType' => $srf->service_type,
                'urgency' => $srf->urgency,
                'description' => $srf->description,
                'duration' => $srf->duration,
                'estimatedCost' => (float) $srf->estimated_cost,
                'justification' => $srf->justification,
                'requester' => $srf->requester_name,
                'requesterId' => (string) $srf->requester_id,
                'date' => $srf->date->format('Y-m-d'),
                'status' => $srf->status,
                'currentStage' => $srf->current_stage,
                'approvalHistory' => $srf->approval_history ?? [],
                'rejectionReason' => $srf->rejection_reason,
            ];
        }));
    }

    /**
     * Create new SRF
     * Only employees (staff) can create SRF
     */
    public function store(Request $request)
    {
        // Only employees can create SRF
        $user = $request->user();
        if (!$user || $user->role !== 'employee') {
            return response()->json([
                'success' => false,
                'error' => 'Only staff members can create Service Request Forms. Please contact your administrator.',
            ], 403);
        }

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'serviceType' => 'required|string|max:255',
            'urgency' => 'required|in:Low,Medium,High,Critical',
            'description' => 'required|string',
            'duration' => 'required|string',
            'estimatedCost' => 'required|numeric|min:0',
            'justification' => 'required|string',
            'invoice' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg|max:10240', // Optional invoice upload (10MB max)
            'invoice_onedrive_url' => 'nullable|url|max:500', // Optional OneDrive URL
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $user = $request->user();

        // Handle invoice upload if provided
        $invoiceUrl = null;
        $invoiceShareUrl = null;
        $srfId = SRF::generateSRFId();
        
        if ($request->hasFile('invoice')) {
            $invoiceFile = $request->file('invoice');
            $oneDriveService = app(\App\Services\OneDriveService::class);
            
            if ($oneDriveService) {
                try {
                    $year = date('Y');
                    $folder = "SRFs/{$year}/{$srfId}";
                    $invoiceFileName = "Invoice_{$srfId}." . $invoiceFile->getClientOriginalExtension();
                    
                    $oneDriveResult = $oneDriveService->uploadFile($invoiceFile, $folder, $invoiceFileName);
                    $invoiceUrl = $oneDriveResult['webUrl'];
                    
                    // Create sharing link
                    try {
                        $invoiceShareUrl = $oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                    } catch (\Exception $e) {
                        \Log::warning('Failed to create invoice sharing link', [
                            'error' => $e->getMessage(),
                            'srf_id' => $srfId
                        ]);
                    }
                } catch (\Exception $e) {
                    \Log::error('OneDrive invoice upload failed, falling back to local storage', [
                        'error' => $e->getMessage(),
                        'srf_id' => $srfId
                    ]);
                    // Fallback to local storage
                    $disk = config('filesystems.documents_disk', 'public');
                    $invoiceFileName = "invoice_{$srfId}_" . time() . "." . $invoiceFile->getClientOriginalExtension();
                    $invoicePath = "srfs/{$srfId}/{$invoiceFileName}";
                    $invoiceFile->storeAs(dirname($invoicePath), basename($invoicePath), $disk);
                    $invoiceUrl = \Storage::disk($disk)->url($invoicePath);
                    $invoiceShareUrl = $invoiceUrl;
                }
            } else {
                // No OneDrive, use local storage
                $disk = config('filesystems.documents_disk', 'public');
                $invoiceFileName = "invoice_{$srfId}_" . time() . "." . $invoiceFile->getClientOriginalExtension();
                $invoicePath = "srfs/{$srfId}/{$invoiceFileName}";
                $invoiceFile->storeAs(dirname($invoicePath), basename($invoicePath), $disk);
                $invoiceUrl = \Storage::disk($disk)->url($invoicePath);
                $invoiceShareUrl = $invoiceUrl;
            }
        } else if ($request->has('invoice_onedrive_url')) {
            // Use provided OneDrive URL
            $invoiceUrl = $request->invoice_onedrive_url;
            $invoiceShareUrl = $request->invoice_onedrive_url;
        }

        $srf = SRF::create([
            'srf_id' => $srfId,
            'title' => $request->title,
            'service_type' => $request->serviceType,
            'urgency' => $request->urgency,
            'description' => $request->description,
            'duration' => $request->duration,
            'estimated_cost' => $request->estimatedCost,
            'justification' => $request->justification,
            'requester_id' => $user->id,
            'requester_name' => $user->name,
            'date' => now(),
            'status' => 'Pending',
            'current_stage' => 'procurement',
            'approval_history' => [],
            'invoice_url' => $invoiceUrl,
            'invoice_share_url' => $invoiceShareUrl,
        ]);

        return response()->json([
            'id' => $srf->srf_id,
            'title' => $srf->title,
            'serviceType' => $srf->service_type,
            'urgency' => $srf->urgency,
            'description' => $srf->description,
            'duration' => $srf->duration,
            'estimatedCost' => (float) $srf->estimated_cost,
            'justification' => $srf->justification,
            'requester' => $srf->requester_name,
            'requesterId' => (string) $srf->requester_id,
            'date' => $srf->date->format('Y-m-d'),
            'status' => $srf->status,
            'currentStage' => $srf->current_stage,
        ], 201);
    }

    /**
     * Update SRF
     */
    public function update(Request $request, $id)
    {
        $srf = SRF::where('srf_id', $id)->first();

        if (!$srf) {
            return response()->json([
                'success' => false,
                'error' => 'SRF not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if (!in_array($srf->status, ['Pending', 'Rejected'])) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot update SRF in current status',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Normalize urgency to proper case
        if ($request->has('urgency')) {
            $request->merge([
                'urgency' => ucfirst(strtolower($request->urgency))
            ]);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'serviceType' => 'sometimes|required|string|max:255',
            'urgency' => 'sometimes|required|in:Low,Medium,High,Critical',
            'description' => 'sometimes|required|string',
            'duration' => 'sometimes|required|string',
            'estimatedCost' => 'sometimes|required|numeric|min:0',
            'justification' => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $updateData = [];
        if ($request->has('title')) $updateData['title'] = $request->title;
        if ($request->has('serviceType')) $updateData['service_type'] = $request->serviceType;
        if ($request->has('urgency')) $updateData['urgency'] = $request->urgency;
        if ($request->has('description')) $updateData['description'] = $request->description;
        if ($request->has('duration')) $updateData['duration'] = $request->duration;
        if ($request->has('estimatedCost')) $updateData['estimated_cost'] = $request->estimatedCost;
        if ($request->has('justification')) $updateData['justification'] = $request->justification;

        if ($srf->status === 'Rejected') {
            $updateData['status'] = 'Pending';
            $updateData['rejection_reason'] = null;
        }

        $srf->update($updateData);
        $srf->refresh();

        return response()->json([
            'id' => $srf->srf_id,
            'title' => $srf->title,
            'serviceType' => $srf->service_type,
            'urgency' => $srf->urgency,
            'description' => $srf->description,
            'duration' => $srf->duration,
            'estimatedCost' => (float) $srf->estimated_cost,
            'justification' => $srf->justification,
            'requester' => $srf->requester_name,
            'requesterId' => (string) $srf->requester_id,
            'date' => $srf->date->format('Y-m-d'),
            'status' => $srf->status,
            'currentStage' => $srf->current_stage,
        ]);
    }
}
