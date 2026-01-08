<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use App\Services\VendorApprovalService;
use App\Services\VendorDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class VendorController extends Controller
{
    /**
     * Get all vendors
     */
    public function index(Request $request)
    {
        $query = Vendor::query();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $vendors = $query->orderBy('name')->get();

        return response()->json($vendors->map(function($vendor) {
            return [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
                'category' => $vendor->category,
                'rating' => $vendor->rating ? (float) $vendor->rating : 0,
                'totalOrders' => $vendor->total_orders,
                'status' => $vendor->status,
                'email' => $vendor->email,
                'phone' => $vendor->phone,
                'address' => $vendor->address,
                'taxId' => $vendor->tax_id,
                'contactPerson' => $vendor->contact_person,
            ];
        }));
    }

    /**
     * Get vendor by ID
     */
    public function show($id)
    {
        $vendor = Vendor::where('vendor_id', $id)->first();

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'id' => $vendor->vendor_id,
            'name' => $vendor->name,
            'category' => $vendor->category,
            'rating' => $vendor->rating ? (float) $vendor->rating : 0,
            'totalOrders' => $vendor->total_orders,
            'status' => $vendor->status,
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'address' => $vendor->address,
            'taxId' => $vendor->tax_id,
            'contactPerson' => $vendor->contact_person,
            'notes' => $vendor->notes,
        ]);
    }

    /**
     * Register new vendor (public endpoint)
     */
    public function register(Request $request, VendorDocumentService $documentService)
    {
        // Normalize email (trim and lowercase) for consistent checking
        $email = strtolower(trim($request->email));
        
        // Check if registration with this email already exists (case-insensitive)
        $existingRegistration = VendorRegistration::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
        
        if ($existingRegistration) {
            // If it's pending, return success (idempotent - same as if they just submitted)
            if (strtolower($existingRegistration->status) === 'pending') {
                return response()->json([
                    'success' => true,
                    'message' => 'Vendor registration already submitted and pending approval',
                    'registration' => [
                        'id' => $existingRegistration->id,
                        'companyName' => $existingRegistration->company_name,
                        'status' => $existingRegistration->status,
                    ]
                ], 200);
            }
            
            // If it's approved or rejected, return appropriate message
            return response()->json([
                'success' => false,
                'error' => strtolower($existingRegistration->status) === 'approved'
                    ? 'A vendor registration with this email has already been approved.'
                    : 'A vendor registration with this email already exists.',
                'code' => 'DUPLICATE_EMAIL'
            ], 422);
        }

        // Prepare validation data with normalized email
        $validationData = $request->all();
        $validationData['email'] = $email;

        // Note: We don't use 'unique' rule here because we check manually above
        // This prevents false positives from case sensitivity or timing issues
        $validator = Validator::make($validationData, [
            'companyName' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'email' => 'required|email', // Removed unique rule - we check manually above
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'taxId' => 'nullable|string|max:255',
            'contactPerson' => 'nullable|string|max:255',
            'documents' => 'nullable|array',
            'documents.*' => 'file|max:10240', // Max 10MB per file
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $registration = VendorRegistration::create([
            'company_name' => $request->companyName,
            'category' => $request->category,
            'email' => $email, // Use normalized email
            'phone' => $request->phone,
            'address' => $request->address,
            'tax_id' => $request->taxId,
            'contact_person' => $request->contactPerson,
            'status' => VendorRegistration::STATUS_PENDING,
        ]);

        // Handle document uploads if provided
        if ($request->hasFile('documents')) {
            $documents = $request->file('documents');
            $documentService->storeDocuments($registration, $documents);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor registration submitted successfully',
            'registration' => [
                'id' => $registration->id,
                'companyName' => $registration->company_name,
                'status' => $registration->status,
            ]
        ], 201);
    }

    /**
     * Get all vendor registrations (procurement_manager and supply_chain_director only)
     */
    public function registrations(Request $request)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $query = VendorRegistration::with(['vendor', 'approver']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $registrations = $query->orderBy('created_at', 'desc')->get();

        $mappedRegistrations = $registrations->map(function($reg) {
            // Format documents with download URLs using VendorDocumentService
            // documents is cast to array in the model, so check if it's an array
            $formattedDocuments = [];
            $documentMetadata = is_array($reg->documents) ? $reg->documents : [];
            $documentService = app(VendorDocumentService::class);
            
            foreach ($documentMetadata as $doc) {
                $filePath = $doc['file_path'] ?? null;
                $fileUrl = null;
                
            if ($filePath) {
                try {
                    $fileUrl = $documentService->getDocumentUrl($filePath, $doc['id'] ?? null, $reg->id);
                } catch (\Exception $e) {
                    \Log::warning("Failed to generate document URL for {$filePath}: " . $e->getMessage());
                    // Fallback to API download endpoint
                    $fileUrl = url("/api/vendors/registrations/{$reg->id}/documents/{$doc['id']}/download");
                }
            }
                
                $formattedDocuments[] = [
                    'id' => (string) ($doc['id'] ?? ''),
                    'type' => $doc['file_type'] ?? null,
                    'fileName' => $doc['file_name'] ?? 'Unknown',
                    'name' => $doc['file_name'] ?? 'Unknown',
                    'filePath' => $filePath,
                    'fileUrl' => $fileUrl,
                    'fileSize' => $doc['file_size'] ?? null,
                    'fileData' => $fileUrl,
                    'uploadedAt' => $doc['uploaded_at'] ?? now()->toIso8601String(),
                ];
            }

            return [
                'id' => (string) $reg->id, // Ensure ID is a string for frontend
                'companyName' => $reg->company_name,
                'category' => $reg->category,
                'email' => $reg->email,
                'phone' => $reg->phone,
                'address' => $reg->address,
                'taxId' => $reg->tax_id,
                'contactPerson' => $reg->contact_person,
                'status' => $reg->status,
                'rejectionReason' => $reg->rejection_reason,
                'approvalRemarks' => $reg->approval_remarks,
                'vendorId' => $reg->vendor ? $reg->vendor->vendor_id : null,
                'documents' => $formattedDocuments,
                'createdAt' => $reg->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mappedRegistrations,
        ]);
    }

    /**
     * Get a single vendor registration by ID
     */
    public function getRegistration(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::with(['vendor', 'approver'])->find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Format documents with download URLs using VendorDocumentService
        // documents is cast to array in the model, so check if it's an array
        $formattedDocuments = [];
        $documentMetadata = is_array($registration->documents) ? $registration->documents : [];
        $documentService = app(VendorDocumentService::class);
        
        foreach ($documentMetadata as $doc) {
            $filePath = $doc['file_path'] ?? null;
            $fileUrl = null;
            
            if ($filePath) {
                try {
                    $fileUrl = $documentService->getDocumentUrl($filePath, $doc['id'] ?? null, $registration->id);
                } catch (\Exception $e) {
                    \Log::warning("Failed to generate document URL for {$filePath}: " . $e->getMessage());
                    // Fallback to API download endpoint
                    $fileUrl = url("/api/vendors/registrations/{$registration->id}/documents/{$doc['id']}/download");
                }
            }
            
            $formattedDocuments[] = [
                'id' => (string) ($doc['id'] ?? ''),
                'type' => $doc['file_type'] ?? null,
                'fileName' => $doc['file_name'] ?? 'Unknown',
                'name' => $doc['file_name'] ?? 'Unknown',
                'filePath' => $filePath,
                'fileUrl' => $fileUrl,
                'fileSize' => $doc['file_size'] ?? null,
                'fileData' => $fileUrl,
                'uploadedAt' => $doc['uploaded_at'] ?? now()->toIso8601String(),
            ];
        }

        $mappedRegistration = [
            'id' => (string) $registration->id,
            'companyName' => $registration->company_name,
            'category' => $registration->category,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'address' => $registration->address,
            'taxId' => $registration->tax_id,
            'contactPerson' => $registration->contact_person,
            'status' => $registration->status,
            'rejectionReason' => $registration->rejection_reason,
            'approvalRemarks' => $registration->approval_remarks,
            'vendorId' => $registration->vendor ? $registration->vendor->vendor_id : null,
            'documents' => $formattedDocuments,
            'approvedBy' => $registration->approver ? [
                'id' => $registration->approver->id,
                'name' => $registration->approver->name,
                'email' => $registration->approver->email,
            ] : null,
            'approvedAt' => $registration->approved_at ? $registration->approved_at->toIso8601String() : null,
            'createdAt' => $registration->created_at->toIso8601String(),
            'updatedAt' => $registration->updated_at->toIso8601String(),
        ];

        return response()->json([
            'success' => true,
            'data' => $mappedRegistration,
        ]);
    }

    /**
     * Approve vendor registration (procurement_manager and supply_chain_director only)
     */
    public function approveRegistration(Request $request, $id, VendorApprovalService $approvalService)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($registration->status !== VendorRegistration::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'error' => 'Registration is not in Pending status',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'remarks' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        try {
            // Use approval service to handle the complete approval process
            $result = $approvalService->approveVendor($registration, $user->id);

            // Update approval remarks if provided
            if ($request->has('remarks')) {
                $registration->update([
                    'approval_remarks' => $request->remarks,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Vendor registration approved. User account created and email sent.',
                'vendor' => [
                    'id' => $result['vendor']->vendor_id,
                    'name' => $result['vendor']->name,
                    'status' => $result['vendor']->status,
                ],
                'user' => [
                    'id' => $result['user']->id,
                    'email' => $result['user']->email,
                ],
                'registration' => [
                    'id' => $registration->id,
                    'status' => $registration->status,
                ],
                'temporaryPassword' => $result['temporary_password'] ?? null,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate email error
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                return response()->json([
                    'success' => false,
                    'error' => 'A user with this email already exists. The vendor may have already been approved.',
                    'code' => 'DUPLICATE_EMAIL'
                ], 422);
            }
            
            // Log the full error for debugging
            \Log::error('Vendor approval database error: ' . $e->getMessage(), [
                'registration_id' => $id,
                'user_id' => $user->id,
                'sql_state' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return detailed error in non-production or if debug is enabled
            $errorMessage = config('app.debug') || config('app.env') !== 'production'
                ? $e->getMessage()
                : 'Database error during vendor approval. Please check the logs.';
            
            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'code' => 'DATABASE_ERROR',
                'sql_state' => $e->getCode(),
            ], 500);
        } catch (\Exception $e) {
            // Log the full error for debugging
            \Log::error('Vendor approval error: ' . $e->getMessage(), [
                'registration_id' => $id,
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return detailed error in non-production or if debug is enabled
            $errorMessage = config('app.debug') || config('app.env') !== 'production'
                ? $e->getMessage() . ' (File: ' . basename($e->getFile()) . ', Line: ' . $e->getLine() . ')'
                : ($e->getMessage() ?: 'An unexpected error occurred during vendor approval.');
            
            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'code' => 'APPROVAL_ERROR'
            ], 500);
        }
    }

    /**
     * Reject vendor registration (procurement_manager and supply_chain_director only)
     */
    public function rejectRegistration(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $registration = VendorRegistration::find($id);

        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        if ($registration->status !== VendorRegistration::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'error' => 'Registration is not in Pending status',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rejectionReason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update registration status
        $registration->update([
            'status' => VendorRegistration::STATUS_REJECTED,
            'rejection_reason' => $request->rejectionReason,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor registration rejected',
            'registration' => [
                'id' => $registration->id,
                'status' => $registration->status,
                'rejectionReason' => $registration->rejection_reason,
            ]
        ]);
    }

    /**
     * Update vendor credentials (procurement_manager and supply_chain_director only)
     */
    public function updateVendorCredentials(Request $request, $id)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $vendor = Vendor::where('vendor_id', $id)->first();

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Find the user account associated with this vendor
        $vendorUser = User::where('vendor_id', $vendor->id)->first();

        if (!$vendorUser) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor user account not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Update password and force change on next login
        $vendorUser->update([
            'password' => Hash::make($request->newPassword),
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor credentials updated. Vendor will be required to change password on next login.',
            'user' => [
                'id' => $vendorUser->id,
                'email' => $vendorUser->email,
            ]
        ]);
    }

    /**
     * Download a vendor registration document
     */
    public function downloadDocument(Request $request, $registrationId, $documentId, VendorDocumentService $documentService)
    {
        $user = $request->user();

        // Check permission - allow procurement manager, supply chain director, and executive-level roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain', // alias for supply_chain_director
            'executive',
            'chairman',
            'admin'
        ];
        
        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Verify registration exists
        $registration = VendorRegistration::find($registrationId);
        if (!$registration) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor registration not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Find the document
        $document = VendorRegistrationDocument::where('id', $documentId)
            ->where('vendor_registration_id', $registrationId)
            ->first();

        if (!$document) {
            return response()->json([
                'success' => false,
                'error' => 'Document not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get document content
        $content = $documentService->getDocumentContent($document);

        if ($content === false) {
            return response()->json([
                'success' => false,
                'error' => 'Document file not found in storage',
                'code' => 'FILE_NOT_FOUND'
            ], 404);
        }

        // Return file as download
        return response($content)
            ->header('Content-Type', $document->file_type)
            ->header('Content-Disposition', 'attachment; filename="' . $document->file_name . '"')
            ->header('Content-Length', strlen($content));
    }
}
