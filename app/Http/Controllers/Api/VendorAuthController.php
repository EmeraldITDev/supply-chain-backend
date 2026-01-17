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

            // Create authentication token
            $token = $user->createToken('vendor-auth-token')->plainTextToken;

            Log::info('Vendor logged in successfully', [
                'vendor_id' => $vendor->vendor_id,
                'email' => $vendor->email
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor' => [
                        'id' => $vendor->vendor_id,
                        'name' => $vendor->name,
                        'email' => $vendor->email,
                        'phone' => $vendor->phone,
                        'address' => $vendor->address,
                        'contactPerson' => $vendor->contact_person,
                        'category' => $vendor->category,
                        'status' => $vendor->status,
                        'rating' => (float) ($vendor->rating ?? 0),
                    ],
                    'token' => $token,
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

            // Get vendor record
            $vendor = Vendor::where('user_id', $user->id)
                ->orWhere('id', $user->vendor_id)
                ->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendor profile not found',
                    'code' => 'NOT_FOUND'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor' => [
                        'id' => $vendor->vendor_id,
                        'name' => $vendor->name,
                        'email' => $vendor->email,
                        'phone' => $vendor->phone,
                        'address' => $vendor->address,
                        'contactPerson' => $vendor->contact_person,
                        'category' => $vendor->category,
                        'status' => $vendor->status,
                        'rating' => (float) ($vendor->rating ?? 0),
                    ],
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'mustChangePassword' => $user->must_change_password ?? false,
                    ]
                ]
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
            'data' => [
                'vendor' => [
                    'id' => $vendor->vendor_id,
                    'name' => $vendor->name,
                    'email' => $vendor->email,
                    'phone' => $vendor->phone,
                    'address' => $vendor->address,
                    'contactPerson' => $vendor->contact_person,
                    'category' => $vendor->category,
                    'status' => $vendor->status,
                    'rating' => (float) ($vendor->rating ?? 0),
                    'updatedAt' => $vendor->updated_at->toIso8601String(),
                ]
            ]
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
            'data' => [
                'vendor' => [
                    'id' => $vendor->vendor_id,
                    'name' => $vendor->name,
                    'email' => $vendor->email,
                    'phone' => $vendor->phone,
                    'address' => $vendor->address,
                    'contactPerson' => $vendor->contact_person,
                    'category' => $vendor->category,
                    'status' => $vendor->status,
                    'rating' => (float) ($vendor->rating ?? 0),
                    'totalOrders' => $vendor->total_orders ?? 0,
                    'taxId' => $vendor->tax_id,
                    'createdAt' => $vendor->created_at->toIso8601String(),
                    'updatedAt' => $vendor->updated_at->toIso8601String(),
                ],
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'mustChangePassword' => $user->must_change_password ?? false,
                ]
            ]
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
}
