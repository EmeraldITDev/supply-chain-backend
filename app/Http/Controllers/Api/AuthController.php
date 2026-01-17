<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Check if user has access to supply chain system
     */
    private function hasSupplyChainAccess(User $user): bool
    {
        // Check if user is a vendor (vendors have access to vendor portal)
        if ($user->role === 'vendor' || $user->hasRole('vendor')) {
            return true;
        }

        // Allowed roles (check both Spatie roles and direct role field)
        $allowedRoles = [
            'procurement_manager',
            'procurement', // Added for test users
            'supply_chain_director',
            'supply_chain', // Added for test users
            'logistics_manager',
            'logistics', // Added for test users
            'finance',
            'finance_officer', // Added for test users
            'executive',
            'chairman',
            'employee', // Added - regular employees can access to create MRFs
            'admin', // Added for admin users
        ];

        // Check direct role field first (for users without Spatie roles)
        if ($user->role && in_array($user->role, $allowedRoles)) {
            return true;
        }

        // Check if user has any of the allowed Spatie roles
        if ($user->hasAnyRole($allowedRoles)) {
            return true;
        }

        // Check employee job_title if user has employee record
        if ($user->employee_id) {
            $employee = Employee::find($user->employee_id);
            if ($employee) {
                $allowedTitles = [
                    'Procurement Manager',
                    'Executive',
                    'Chairman',
                    'Supply Chain Director',
                    'Logistics Manager',
                ];

                // Exact match
                if (in_array($employee->job_title, $allowedTitles)) {
                    return true;
                }

                // Check if job_title contains "Finance"
                if (stripos($employee->job_title, 'Finance') !== false) {
                    return true;
                }

                // Check department
                if (in_array(strtolower($employee->department ?? ''), ['supply chain', 'procurement', 'logistics', 'finance'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Login user and return token
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'remember_me' => 'nullable|boolean',
        ]);

        $user = User::with('employee')->where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user has supply chain access
        if (!$this->hasSupplyChainAccess($user)) {
            throw ValidationException::withMessages([
                'email' => ['You do not have access to the Supply Chain Management system. Please contact your administrator.'],
            ]);
        }

        // Determine token expiration based on remember_me
        // Remember me: 30 days, Regular session: 1 day
        $rememberMe = $request->boolean('remember_me', true); // Default to true for persistent login
        $expiresAt = $rememberMe 
            ? now()->addDays(30)  // 30 days for "remember me"
            : now()->addDay();    // 1 day for regular session

        // Create token with expiration
        $tokenName = $rememberMe ? 'remember-token' : 'session-token';
        $token = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

        // Get user role (from Spatie or fallback to role field)
        $role = $user->getRoleNames()->first() ?? $user->role ?? 'employee';
        
        // Get department from employee or user
        $department = $user->employee->department ?? $user->department ?? null;

        // Check if user must change password
        $requiresPasswordChange = $user->must_change_password ?? false;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $role,
                'department' => $department,
                'employeeId' => $user->employee_id,
                'createdAt' => $user->created_at->toIso8601String(),
            ],
            'token' => $token,
            'expiresAt' => $expiresAt->toIso8601String(),
            'requiresPasswordChange' => $requiresPasswordChange,
        ]);
    }

    /**
     * Logout current user
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('employee');
        $token = $request->user()->currentAccessToken();

        // Get user role (from Spatie or fallback to role field)
        $role = $user->getRoleNames()->first() ?? $user->role ?? 'employee';
        
        // Get department from employee or user
        $department = $user->employee->department ?? $user->department ?? null;

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $role,
            'department' => $department,
            'employeeId' => $user->employee_id,
            'createdAt' => $user->created_at->toIso8601String(),
            'requiresPasswordChange' => $user->must_change_password ?? false,
            'tokenExpiresAt' => $token->expires_at ? $token->expires_at->toIso8601String() : null,
        ]);
    }

    /**
     * Refresh authentication token (extends session)
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();
        $currentToken = $request->user()->currentAccessToken();
        
        // Determine if this was a remember token or session token
        $isRememberToken = str_contains($currentToken->name, 'remember');
        
        // Create new token with same expiration logic
        $expiresAt = $isRememberToken 
            ? now()->addDays(30)  // 30 days for "remember me"
            : now()->addDay();    // 1 day for regular session
        
        $tokenName = $isRememberToken ? 'remember-token' : 'session-token';
        
        // Delete old token
        $currentToken->delete();
        
        // Create new token
        $newToken = $user->createToken($tokenName, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $newToken,
            'expiresAt' => $expiresAt->toIso8601String(),
        ]);
    }

    /**
     * Change password for authenticated user
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'currentPassword' => 'required',
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        // Verify current password
        if (!Hash::check($request->currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['The current password is incorrect.'],
            ]);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->newPassword),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'department' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        $updateData = [];

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->has('department')) {
            $updateData['department'] = $request->department;
        }

        // If user has employee record, update it too
        if ($user->employee_id) {
            $employee = \App\Models\Employee::find($user->employee_id);
            if ($employee) {
                if ($request->has('name')) {
                    $employee->update(['full_name' => $request->name]);
                }
                if ($request->has('department')) {
                    $employee->update(['department' => $request->department]);
                }
            }
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'department' => $user->department,
            ]
        ]);
    }

    /**
     * Force password change for vendors on first login
     * This is used when vendor logs in with temporary password
     */
    public function forcePasswordChange(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'temporaryPassword' => 'required',
            'newPassword' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['User not found.'],
            ]);
        }

        // Verify temporary password
        if (!Hash::check($request->temporaryPassword, $user->password)) {
            throw ValidationException::withMessages([
                'temporaryPassword' => ['The temporary password is incorrect.'],
            ]);
        }

        // Check if user must change password
        if (!$user->must_change_password) {
            throw ValidationException::withMessages([
                'newPassword' => ['Password change is not required for this account.'],
            ]);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->newPassword),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully. You can now login with your new password.',
        ]);
    }
}
