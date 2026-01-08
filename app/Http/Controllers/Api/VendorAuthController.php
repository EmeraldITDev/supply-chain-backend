<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class VendorAuthController extends Controller
{
    /**
     * Vendor login
     * 
     * Authenticates vendor users and returns JWT token
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'remember_me' => 'nullable|boolean',
        ]);

        // Find user by email where vendor_id is set
        $user = User::with('vendor')
            ->where('email', $request->email)
            ->whereNotNull('vendor_id')
            ->first();

        // Check if user exists and is a vendor
        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['No vendor account found with this email address.'],
            ]);
        }

        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if vendor exists
        if (!$user->vendor) {
            throw ValidationException::withMessages([
                'email' => ['Vendor information not found. Please contact support.'],
            ]);
        }

        // Check vendor status - only 'Active' vendors can login
        if ($user->vendor->status !== 'Active') {
            $statusMessage = match($user->vendor->status) {
                'Pending' => 'Your vendor account is pending approval. Please wait for approval from the procurement team.',
                'Inactive' => 'Your vendor account has been deactivated. Please contact support for assistance.',
                'Suspended' => 'Your vendor account has been suspended. Please contact support for assistance.',
                default => 'Your vendor account is not active. Please contact support.',
            };

            return response()->json([
                'success' => false,
                'error' => $statusMessage,
                'code' => 'VENDOR_NOT_ACTIVE',
                'vendorStatus' => $user->vendor->status,
            ], 401);
        }

        // Check if user must change password (first login with temporary password)
        if ($user->must_change_password) {
            return response()->json([
                'success' => false,
                'error' => 'You must change your temporary password before logging in.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
                'requiresPasswordChange' => true,
                'email' => $user->email,
            ], 403);
        }

        // Determine token expiration based on remember_me
        $rememberMe = $request->boolean('remember_me', true);
        $expiresAt = $rememberMe 
            ? now()->addDays(30)  // 30 days for "remember me"
            : now()->addDay();    // 1 day for regular session

        // Create token
        $tokenName = $rememberMe ? 'vendor-remember-token' : 'vendor-session-token';
        $token = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => 'vendor',
                'createdAt' => $user->created_at->toIso8601String(),
            ],
            'vendor' => [
                'id' => $user->vendor->vendor_id,
                'name' => $user->vendor->name,
                'category' => $user->vendor->category,
                'email' => $user->vendor->email,
                'phone' => $user->vendor->phone,
                'address' => $user->vendor->address,
                'contactPerson' => $user->vendor->contact_person,
                'status' => $user->vendor->status,
                'rating' => $user->vendor->rating ? (float) $user->vendor->rating : 0,
                'totalOrders' => $user->vendor->total_orders,
            ],
            'token' => $token,
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Vendor logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Get authenticated vendor info
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('vendor');
        $token = $request->user()->currentAccessToken();

        if (!$user->vendor) {
            return response()->json([
                'success' => false,
                'error' => 'Vendor information not found',
                'code' => 'VENDOR_NOT_FOUND'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => 'vendor',
                'createdAt' => $user->created_at->toIso8601String(),
            ],
            'vendor' => [
                'id' => $user->vendor->vendor_id,
                'name' => $user->vendor->name,
                'category' => $user->vendor->category,
                'email' => $user->vendor->email,
                'phone' => $user->vendor->phone,
                'address' => $user->vendor->address,
                'contactPerson' => $user->vendor->contact_person,
                'status' => $user->vendor->status,
                'rating' => $user->vendor->rating ? (float) $user->vendor->rating : 0,
                'totalOrders' => $user->vendor->total_orders,
            ],
            'tokenExpiresAt' => $token->expires_at ? $token->expires_at->toIso8601String() : null,
        ]);
    }
}
