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
        // Allowed Spatie roles
        $allowedRoles = [
            'procurement_manager',
            'supply_chain_director',
            'logistics_manager',
            'finance',
            'executive',
            'chairman',
        ];

        // Check if user has any of the allowed roles
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

        $token = $user->createToken('auth-token')->plainTextToken;

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
