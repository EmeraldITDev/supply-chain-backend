<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRating;
use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use App\Models\Quotation;
use App\Services\NotificationService;
use App\Services\VendorApprovalService;
use App\Services\VendorDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class VendorController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
    /**
     * Find vendor by ID (supports both primary key and vendor_id)
     * 
     * @param mixed $id - Can be primary key (integer) or vendor_id (string like "V001")
     * @return Vendor|null
     */
    private function findVendor($id): ?Vendor
    {
        // Try to find by vendor_id first (business identifier like "V001")
        $vendor = Vendor::where('vendor_id', $id)->first();
        
        // If not found and $id is numeric, try by primary key
        if (!$vendor && is_numeric($id)) {
            $vendor = Vendor::find($id);
        }
        
        return $vendor;
    }

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

        // Send notification to procurement managers
        $this->notificationService->notifyVendorRegistration($registration);

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
                $fileShareUrl = $doc['file_share_url'] ?? null;
                
                if ($filePath && !$fileShareUrl) {
                    try {
                        $fileUrl = $documentService->getDocumentUrl($filePath, $doc['id'] ?? null, $reg->id);
                    } catch (\Exception $e) {
                        \Log::warning("Failed to generate document URL for {$filePath}: " . $e->getMessage());
                        // Fallback to API download endpoint
                        $fileUrl = url("/api/vendors/registrations/{$reg->id}/documents/{$doc['id']}/download");
                    }
                } else if ($fileShareUrl) {
                    // If we have a OneDrive share URL, use it as the file URL
                    $fileUrl = $fileShareUrl;
                }
                
                $formattedDocuments[] = [
                    'id' => (string) ($doc['id'] ?? ''),
                    'type' => $doc['file_type'] ?? null,
                    'fileName' => $doc['file_name'] ?? 'Unknown',
                    'name' => $doc['file_name'] ?? 'Unknown',
                    'filePath' => $filePath,
                    'fileUrl' => $fileUrl,
                    'file_share_url' => $fileShareUrl,
                    'fileShareUrl' => $fileShareUrl, // Also include camelCase for frontend compatibility
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
            $fileShareUrl = $doc['file_share_url'] ?? null;
            
            if ($filePath && !$fileShareUrl) {
                try {
                    $fileUrl = $documentService->getDocumentUrl($filePath, $doc['id'] ?? null, $registration->id);
                } catch (\Exception $e) {
                    \Log::warning("Failed to generate document URL for {$filePath}: " . $e->getMessage());
                    // Fallback to API download endpoint
                    $fileUrl = url("/api/vendors/registrations/{$registration->id}/documents/{$doc['id']}/download");
                }
            } else if ($fileShareUrl) {
                // If we have a OneDrive share URL, use it as the file URL
                $fileUrl = $fileShareUrl;
            }
            
            $formattedDocuments[] = [
                'id' => (string) ($doc['id'] ?? ''),
                'type' => $doc['file_type'] ?? null,
                'fileName' => $doc['file_name'] ?? 'Unknown',
                'name' => $doc['file_name'] ?? 'Unknown',
                'filePath' => $filePath,
                'fileUrl' => $fileUrl,
                'file_share_url' => $fileShareUrl,
                'fileShareUrl' => $fileShareUrl, // Also include camelCase for frontend compatibility
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
     * Supports both manual password setting and automatic password reset
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

        // Find vendor by either primary key or vendor_id
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND',
                'debug' => [
                    'searchedId' => $id,
                    'searchedType' => is_numeric($id) ? 'numeric (tried both primary key and vendor_id)' : 'string (tried vendor_id only)'
                ]
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

        // Check if this is an automatic password reset request
        if ($request->boolean('resetPassword', false)) {
            // Generate random secure password (12 characters)
            $newPassword = Str::random(12);
            
            // Update password and force change on next login
            $vendorUser->update([
                'password' => Hash::make($newPassword),
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vendor password has been reset. The vendor will be required to change this temporary password on next login.',
                'data' => [
                    'temporaryPassword' => $newPassword,
                ],
                'user' => [
                    'id' => $vendorUser->id,
                    'email' => $vendorUser->email,
                ]
            ]);
        }

        // Manual password setting (existing behavior)
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
     * Delete a vendor
     * Soft deletes the vendor and deactivates their user account
     */
    public function destroy(Request $request, $id)
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

        // Find vendor by either primary key or vendor_id
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND',
                'debug' => [
                    'searchedId' => $id,
                    'searchedType' => is_numeric($id) ? 'numeric (tried both primary key and vendor_id)' : 'string (tried vendor_id only)'
                ]
            ], 404);
        }

        // Check if vendor has active orders or quotations
        $activeQuotations = $vendor->quotations()
            ->whereIn('status', ['Pending', 'Approved'])
            ->count();

        if ($activeQuotations > 0) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot delete vendor with active quotations. Please complete or reject all pending quotations first.',
                'code' => 'VENDOR_HAS_ACTIVE_QUOTATIONS',
                'activeQuotations' => $activeQuotations
            ], 422);
        }

        // Deactivate associated user account if exists
        $vendorUser = User::where('vendor_id', $vendor->id)->first();
        if ($vendorUser) {
            // Revoke all tokens
            $vendorUser->tokens()->delete();
            
            // Delete user account
            $vendorUser->delete();
        }

        // Delete the vendor
        $vendorName = $vendor->name;
        $vendorId = $vendor->vendor_id;
        $vendor->delete();

        return response()->json([
            'success' => true,
            'message' => "Vendor '{$vendorName}' has been successfully deleted.",
            'data' => [
                'vendorId' => $vendorId,
                'vendorName' => $vendorName,
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

        // Check if document is in OneDrive (has share URL)
        if ($document->file_share_url) {
            // Redirect to OneDrive share URL for download
            return redirect($document->file_share_url);
        }
        
        // Get document content from local/S3 storage
        $content = $documentService->getDocumentContent($document);

        if ($content === false) {
            return response()->json([
                'success' => false,
                'error' => 'Document file not found in storage. If this is a OneDrive file, please use the share URL.',
                'code' => 'FILE_NOT_FOUND',
                'has_share_url' => !empty($document->file_share_url),
                'share_url' => $document->file_share_url
            ], 404);
        }

        // Determine content type from file extension if not set
        $contentType = $document->file_type;
        if (!$contentType) {
            $extension = strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'txt' => 'text/plain',
                'zip' => 'application/zip',
                'rar' => 'application/x-rar-compressed',
            ];
            $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        // Return file as download
        return response($content)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="' . $document->file_name . '"')
            ->header('Content-Length', strlen($content));
    }

    /**
     * Add rating/comment to vendor
     */
    public function addRating(Request $request, $id)
    {
        $user = $request->user();

        // Validate input
        $validator = Validator::make($request->all(), [
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Find vendor
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Create rating
        $rating = VendorRating::create([
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        // Recalculate vendor average rating
        $this->updateVendorAverageRating($vendor);

        // Get all ratings with user info
        $ratings = VendorRating::where('vendor_id', $vendor->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($r) {
                return [
                    'id' => $r->id,
                    'comment' => $r->comment,
                    'rating' => (float) $r->rating,
                    'createdAt' => $r->created_at->toIso8601String(),
                    'createdBy' => [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                        'email' => $r->user->email,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Rating added successfully',
            'rating' => (float) $vendor->fresh()->rating,
            'comments' => $ratings,
        ]);
    }

    /**
     * Get vendor comments/ratings
     */
    public function getComments($id)
    {
        // Find vendor
        $vendor = $this->findVendor($id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Get all ratings with user info
        $comments = VendorRating::where('vendor_id', $vendor->id)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($r) {
                return [
                    'id' => $r->id,
                    'comment' => $r->comment,
                    'rating' => (float) $r->rating,
                    'createdAt' => $r->created_at->toIso8601String(),
                    'createdBy' => [
                        'id' => $r->user->id,
                        'name' => $r->user->name,
                        'email' => $r->user->email,
                    ],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $comments,
            'vendorRating' => (float) ($vendor->rating ?? 0),
            'totalComments' => $comments->count(),
        ]);
    }

    /**
     * Update vendor average rating
     */
    private function updateVendorAverageRating(Vendor $vendor): void
    {
        $avgRating = VendorRating::where('vendor_id', $vendor->id)
            ->avg('rating');

        $vendor->update([
            'rating' => $avgRating ? round($avgRating, 2) : 0,
        ]);
    }

    /**
     * Get quotations for the logged-in vendor (vendor-specific endpoint)
     */
    public function getVendorQuotations(Request $request)
    {
        $user = $request->user();

        // Verify user is a vendor
        if ($user->role !== 'vendor') {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get vendor from authenticated user - try multiple methods
        $vendor = null;
        
        // Method 1: Try vendor relationship
        if ($user->vendor_id && method_exists($user, 'vendor')) {
            $vendor = $user->vendor;
        }
        
        // Method 2: Find vendor by vendor_id if relationship didn't work
        if (!$vendor && $user->vendor_id) {
            $vendor = Vendor::find($user->vendor_id);
        }
        
        // Method 3: Try finding vendor by email as last resort
        if (!$vendor) {
            $vendor = Vendor::where('email', $user->email)->first();
        }

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found. Please ensure your account is linked to a vendor.',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Build query for quotations
        $query = Quotation::where('vendor_id', $vendor->id)
            ->with(['rfq.mrf', 'rfq.items', 'approver']);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by RFQ ID if provided
        if ($request->has('rfqId')) {
            $rfq = \App\Models\RFQ::where('rfq_id', $request->rfqId)->first();
            if ($rfq) {
                $query->where('rfq_id', $rfq->id);
            }
        }

        // Order by most recent first
        $quotations = $query->orderBy('created_at', 'desc')->get();

        // Format quotations for response
        $formattedQuotations = $quotations->map(function($quotation) use ($vendor) {
            // Safely format dates to prevent time errors
            $formatDate = function($date) {
                if (!$date) {
                    return null;
                }
                try {
                    if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                        return $date->toIso8601String();
                    }
                    return null;
                } catch (\Exception $e) {
                    \Log::warning("Date formatting error: " . $e->getMessage());
                    return null;
                }
            };

            $formatDateOnly = function($date) {
                if (!$date) {
                    return null;
                }
                try {
                    if ($date instanceof \Carbon\Carbon || $date instanceof \DateTime) {
                        return $date->format('Y-m-d');
                    }
                    return null;
                } catch (\Exception $e) {
                    \Log::warning("Date formatting error: " . $e->getMessage());
                    return null;
                }
            };

            return [
                'id' => $quotation->quotation_id,
                'quoteNumber' => $quotation->quote_number,
                'rfqId' => $quotation->rfq ? $quotation->rfq->rfq_id : null,
                'rfqTitle' => $quotation->rfq ? ($quotation->rfq->title ?? $quotation->rfq->description) : null,
                'mrfId' => $quotation->rfq && $quotation->rfq->mrf ? $quotation->rfq->mrf->mrf_id : null,
                'mrfTitle' => $quotation->rfq && $quotation->rfq->mrf ? $quotation->rfq->mrf->title : null,
                'vendorId' => $vendor->vendor_id,
                'vendorName' => $quotation->vendor_name,
                'price' => (float) $quotation->price,
                'totalAmount' => (float) $quotation->total_amount,
                'currency' => $quotation->currency ?? 'NGN',
                'deliveryDays' => $quotation->delivery_days,
                'deliveryDate' => $formatDateOnly($quotation->delivery_date),
                'paymentTerms' => $quotation->payment_terms,
                'validityDays' => $quotation->validity_days,
                'warrantyPeriod' => $quotation->warranty_period,
                'notes' => $quotation->notes,
                'status' => $quotation->status,
                'reviewStatus' => $quotation->review_status ?? 'pending',
                'rejectionReason' => $quotation->rejection_reason,
                'revisionNotes' => $quotation->revision_notes,
                'approvalRemarks' => $quotation->approval_remarks,
                'attachments' => $quotation->attachments ?? [],
                'submittedAt' => $formatDate($quotation->submitted_at),
                'reviewedAt' => $formatDate($quotation->reviewed_at),
                'approvedAt' => $formatDate($quotation->approved_at),
                'approvedBy' => $quotation->approver ? [
                    'id' => $quotation->approver->id,
                    'name' => $quotation->approver->name,
                    'email' => $quotation->approver->email,
                ] : null,
                'createdAt' => $formatDate($quotation->created_at) ?? ($quotation->created_at ? $quotation->created_at : null),
                'updatedAt' => $formatDate($quotation->updated_at) ?? ($quotation->updated_at ? $quotation->updated_at : null),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formattedQuotations,
            'count' => $formattedQuotations->count(),
            'vendor' => [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
            ],
        ]);
    }

    /**
     * Send vendor invitation email
     */
    public function inviteVendor(Request $request)
    {
        $user = $request->user();

        // Check permissions - only procurement managers and higher
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'supply_chain',
            'executive',
            'chairman',
            'admin',
        ];

        if (!in_array($user->role, $allowedRoles)) {
            return response()->json([
                'success' => false,
                'error' => 'Insufficient permissions to invite vendors',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'company_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Check if vendor already exists with this email
        $existingVendor = Vendor::where('email', $request->email)->first();
        if ($existingVendor) {
            return response()->json([
                'success' => false,
                'error' => 'A vendor with this email already exists',
                'code' => 'VENDOR_EXISTS'
            ], 409);
        }

        // Check if there's already a pending registration with this email
        $pendingRegistration = VendorRegistration::where('email', $request->email)
            ->whereIn('status', ['pending', 'Pending'])
            ->first();

        if ($pendingRegistration) {
            return response()->json([
                'success' => false,
                'error' => 'A registration with this email is already pending',
                'code' => 'REGISTRATION_PENDING'
            ], 409);
        }

        // Send invitation email
        $emailService = app(\App\Services\EmailService::class);
        $emailSent = $emailService->sendVendorInvitation(
            $request->email,
            $request->company_name
        );

        if (!$emailSent) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to send invitation email',
                'code' => 'EMAIL_FAILED'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vendor invitation sent successfully',
            'data' => [
                'email' => $request->email,
                'companyName' => $request->company_name,
                'sentAt' => now()->toIso8601String(),
                'sentBy' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]
        ], 200);
    }
}
