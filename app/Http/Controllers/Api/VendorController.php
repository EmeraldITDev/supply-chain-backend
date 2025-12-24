<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use Illuminate\Http\Request;
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
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'companyName' => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'email' => 'required|email|unique:vendor_registrations,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'taxId' => 'nullable|string|max:255',
            'contactPerson' => 'nullable|string|max:255',
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
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'tax_id' => $request->taxId,
            'contact_person' => $request->contactPerson,
            'status' => 'Pending',
        ]);

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
     * Get all vendor registrations (procurement only)
     */
    public function registrations(Request $request)
    {
        $user = $request->user();

        // Check permission
        if (!in_array($user->role, ['procurement', 'admin'])) {
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

        return response()->json($registrations->map(function($reg) {
            return [
                'id' => $reg->id,
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
                'createdAt' => $reg->created_at->toIso8601String(),
            ];
        }));
    }

    /**
     * Approve vendor registration (procurement only)
     */
    public function approveRegistration(Request $request, $id)
    {
        $user = $request->user();

        // Check permission
        if (!in_array($user->role, ['procurement', 'admin'])) {
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

        if ($registration->status !== 'Pending') {
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

        // Create vendor from registration
        $vendor = Vendor::create([
            'vendor_id' => Vendor::generateVendorId(),
            'name' => $registration->company_name,
            'category' => $registration->category,
            'email' => $registration->email,
            'phone' => $registration->phone,
            'address' => $registration->address,
            'tax_id' => $registration->tax_id,
            'contact_person' => $registration->contact_person,
            'status' => 'Active',
            'rating' => 0,
            'total_orders' => 0,
        ]);

        // Update registration
        $registration->update([
            'status' => 'Approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approval_remarks' => $request->remarks,
            'vendor_id' => $vendor->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor registration approved and vendor created',
            'vendor' => [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
                'status' => $vendor->status,
            ],
            'registration' => [
                'id' => $registration->id,
                'status' => $registration->status,
            ]
        ]);
    }
}
