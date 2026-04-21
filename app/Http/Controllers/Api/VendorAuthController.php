<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VendorAuthController extends Controller
{
    /**
     * Vendor login
     */
    public function login(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'code' => 'VALIDATION_ERROR'
                ], 422);
            }

            // Find vendor by email
            $vendor = Vendor::where('email', $request->email)->first();

            if (!$vendor) {
                Log::warning('Vendor login failed: vendor not found', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'error' => 'The provided credentials are incorrect',
                    'code' => 'INVALID_CREDENTIALS'
                ], 401);
            }

            // Check if vendor is approved/active (case-insensitive and trim whitespace)
            $vendorStatus = strtolower(trim($vendor->status));
            $allowedStatuses = ['approved', 'active']; // Allow both approved and active status

            if (!in_array($vendorStatus, $allowedStatuses)) {
                Log::warning('Vendor login failed: not approved/active', [
                    'email' => $request->email,
                    'status' => $vendor->status,
                    'status_normalized' => $vendorStatus,
                    'allowed_statuses' => $allowedStatuses
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'Your vendor account is not yet approved. Please wait for approval.',
                    'code' => 'NOT_APPROVED',
                    'current_status' => $vendor->status
                ], 403);
            }

            // Find associated user account
            $user = User::where('vendor_id', $vendor->id)->first();

            if (!$user) {
                Log::error('Vendor login failed: user account not found', [
                    'vendor_id' => $vendor->vendor_id,
                    'vendor_email' => $vendor->email
                ]);
                return response()->json([
                    'success' => false,
                    'error' => 'User account not found. Please contact support.',
                    'code' => 'USER_NOT_FOUND'
                ], 404);
            }

            // Verify password
            if (!Hash::check($request->password, $user->password)) {
                Log::warning('Vendor login failed: incorrect password', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'error' => 'The provided credentials are incorrect',
                    'code' => 'INVALID_CREDENTIALS'
                ], 401);
            }

            // Create authentication token with expiration (30 days for vendors)
            $expiresAt = now()->addDays(30);
            $token = $user->createToken('vendor-auth-token', ['*'], $expiresAt)->plainTextToken;

            Log::info('Vendor logged in successfully', [
                'vendor_id' => $vendor->vendor_id,
                'email' => $vendor->email
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor' => $this->formatVendorData($vendor),
                    'token' => $token,
                    'expiresAt' => $expiresAt
                        ? \Carbon\Carbon::parse($expiresAt)->toIso8601String()
                    : null,
                    'requiresPasswordChange' => $user->must_change_password ?? false,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Vendor login error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred during login',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again or contact support',
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Vendor logout
     */
    public function logout(Request $request)
    {
        try {
            // Revoke current token
            $request->user()->currentAccessToken()->delete();

            Log::info('Vendor logged out', ['user_id' => $request->user()->id]);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Vendor logout error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Logout failed',
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Get authenticated vendor info
     */
    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $currentToken = $request->user()->currentAccessToken();

            // Get vendor record - use vendor_id from users table
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
                    'error' => 'Vendor profile not found',
                    'code' => 'NOT_FOUND'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $this->formatVendorData($vendor),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'mustChangePassword' => $user->must_change_password ?? false,
                ],
                'tokenExpiresAt' => $currentToken->expires_at
                    ? \Carbon\Carbon::parse($currentToken->expires_at)->toIso8601String()
                    : null,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get vendor info error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to get vendor information',
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Update authenticated vendor's profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Ensure user is a vendor
        if ($user->role !== 'vendor' && !$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get the vendor record
        $vendor = Vendor::find($user->vendor_id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'contact_person' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:500',
            'email' => 'sometimes|email|max:255|unique:vendors,email,' . $vendor->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Build update data
        $updateData = [];

        if ($request->has('contact_person')) {
            $updateData['contact_person'] = $request->contact_person;
            // Also update user name if contact person changes
            $user->update(['name' => $request->contact_person]);
        }

        if ($request->has('phone')) {
            $updateData['phone'] = $request->phone;
        }

        if ($request->has('address')) {
            $updateData['address'] = $request->address;
        }

        if ($request->has('email')) {
            // Update both vendor and user email
            $updateData['email'] = $request->email;
            $user->update(['email' => $request->email]);
        }

        // Update vendor profile
        $vendor->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => $this->formatVendorData($vendor)
        ], 200);
    }

    /**
     * Get authenticated vendor's profile
     */
    public function getProfile(Request $request)
    {
        $user = $request->user();

        // Ensure user is a vendor
        if ($user->role !== 'vendor' && !$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Get the vendor record
        $vendor = Vendor::find($user->vendor_id);

        if (!$vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor profile not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatVendorData($vendor),
        ], 200);
    }

    /**
     * Change vendor password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        // Ensure user is a vendor
        if ($user->role !== 'vendor' && !$user->hasRole('vendor')) {
            return response()->json([
                'success' => false,
                'error' => 'Only vendors can access this endpoint',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'error' => 'Current password is incorrect',
                'code' => 'INVALID_PASSWORD'
            ], 401);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ], 200);
    }

    /**
     * Request password reset (for vendors)
     * Notifies procurement managers when a vendor requests password reset
     */
    public function requestPasswordReset(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Find user by email
        $user = User::where('email', $request->email)
            ->where(function($query) {
                $query->where('role', 'vendor')
                      ->orWhereHas('roles', function($q) {
                          $q->where('name', 'vendor');
                      });
            })
            ->first();

        // Always return success for security (don't reveal if email exists)
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent',
            ], 200);
        }

        // Get vendor information
        $vendor = Vendor::find($user->vendor_id);

        // Generate temporary password
        $temporaryPassword = $this->generateTemporaryPassword();

        // Update user password
        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        // Send password reset email to vendor
        $emailService = app(\App\Services\EmailService::class);
        $emailService->sendPasswordResetEmail(
            $user->email,
            $user->name,
            $temporaryPassword
        );

        // Notify procurement managers about password reset request
        try {
            $notificationService = app(\App\Services\NotificationService::class);

            // Get all procurement managers and supply chain directors
            $procurementManagers = User::whereIn('role', [
                'procurement_manager',
                'supply_chain_director',
                'supply_chain',
                'admin'
            ])->get();

            foreach ($procurementManagers as $manager) {
                // Send notification to each procurement manager
                $manager->notify(new \App\Notifications\VendorPasswordResetRequestNotification(
                    $vendor ?? (object)['vendor_id' => 'N/A', 'name' => $user->name, 'email' => $user->email],
                    $user->email
                ));
            }

            Log::info('Vendor password reset request notification sent to procurement managers', [
                'vendor_email' => $user->email,
                'notified_count' => $procurementManagers->count()
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the password reset
            Log::error('Failed to notify procurement managers about vendor password reset', [
                'vendor_email' => $user->email,
                'error' => $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If the email exists, a password reset link has been sent',
        ], 200);
    }

    /**
     * Generate a secure temporary password
     */
    private function generateTemporaryPassword(): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghijkmnpqrstuvwxyz';
        $numbers = '23456789';
        $special = '!@#$%&*';

        $password = '';
        $password .= substr(str_shuffle($uppercase), 0, 3);
        $password .= substr(str_shuffle($lowercase), 0, 3);
        $password .= substr(str_shuffle($numbers), 0, 3);
        $password .= substr(str_shuffle($special), 0, 3);

        return str_shuffle($password);
    }

    /**
     * Format vendor data with all profile fields
     * Returns a complete vendor object with normalized field names for the frontend
     */
    private function formatVendorData(Vendor $vendor): array
    {
        // Get vendor registration if it exists for additional data
        $registration = $vendor->registrations()->latest()->first();

        // Get documents if they exist
        $documents = null;
        if ($registration && $registration->documents) {
            $documents = is_array($registration->documents)
                ? $registration->documents
                : json_decode($registration->documents, true);
        }

        return [
            // Basic identification
            'id' => $vendor->vendor_id,
            'vendorId' => $vendor->vendor_id,
            'name' => $vendor->name,
            'companyName' => $vendor->name,

            // Contact information
            'email' => $vendor->email,
            'phone' => $vendor->phone,
            'alternatePhone' => $vendor->alternate_phone,
            'contactPerson' => $vendor->contact_person,
            'contactPersonTitle' => $vendor->contact_person_title,
            'contactPersonEmail' => $vendor->contact_person_email,
            'contactPersonPhone' => $vendor->contact_person_phone,

            // Address information
            'address' => $vendor->address,
            'city' => $vendor->city,
            'state' => $vendor->state,
            'postalCode' => $vendor->postal_code,
            'country_code' => $vendor->country_code,
            'countryCode' => $vendor->country_code,

            // Business information
            'category' => $vendor->category,
            'website' => $vendor->website,
            'taxId' => $vendor->tax_id,
            'tax_id' => $vendor->tax_id,
            'yearEstablished' => $vendor->year_established,
            'year_established' => $vendor->year_established,
            'numberOfEmployees' => $vendor->number_of_employees,
            'number_of_employees' => $vendor->number_of_employees,
            'annualRevenue' => $vendor->annual_revenue,
            'annual_revenue' => $vendor->annual_revenue,

            // Status and rating
            'status' => $vendor->status,
            'rating' => (float) ($vendor->rating ?? 0),

            // Statistics
            'totalOrders' => $vendor->total_orders ?? 0,
            'total_orders' => $vendor->total_orders ?? 0,

            // Timestamps
            'createdAt' => $vendor->created_at
                ? \Carbon\Carbon::parse($vendor->created_at)->toIso8601String()
                : null,
            'created_at' => $vendor->created_at
                ? \Carbon\Carbon::parse($vendor->created_at)->toIso8601String()
                : null,
            'updatedAt' => $vendor->updated_at
                ? \Carbon\Carbon::parse($vendor->updated_at)->toIso8601String()
                : null,
            'updated_at' => $vendor->updated_at
                ? \Carbon\Carbon::parse($vendor->updated_at)->toIso8601String()
                : null,

            // Documents
            'documents' => $documents,
            'registration_documents' => $documents,
            'kyc_documents' => $documents,
        ];
    }

    /**
     * Refresh authentication token for vendors (extends session)
     */
    public function refreshToken(Request $request)
    {
        try {
            $user = $request->user();
            $currentToken = $request->user()->currentAccessToken();

            // Create new token with 30 days expiration for vendors
            $expiresAt = now()->addDays(30);
            $tokenName = 'vendor-auth-token';

            // Delete old token
            $currentToken->delete();

            // Create new token
            $newToken = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

            Log::info('Vendor token refreshed', [
                'user_id' => $user->id,
                'vendor_id' => $user->vendor_id
            ]);

            return response()->json([
                'success' => true,
                'token' => $newToken,
                'expiresAt' => $expiresAt->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Vendor token refresh error', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to refresh token',
                'code' => 'SERVER_ERROR'
            ], 500);
        }
    }
}
