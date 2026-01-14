<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    protected PermissionService $permissionService;

    public function __construct(PermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * Get all users (admin only)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->permissionService->canManageUsers($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to manage users',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $query = User::query();

        // Filter by role if provided
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->get()->map(function($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'department' => $user->department,
                'is_admin' => $user->is_admin ?? false,
                'can_manage_users' => $user->can_manage_users ?? false,
                'created_at' => $user->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Create new user (admin only)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$this->permissionService->canManageUsers($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to create users',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:employee,executive,procurement,procurement_manager,supply_chain_director,supply_chain,finance,finance_officer,admin',
            'department' => 'nullable|string|max:255',
            'password' => 'required|string|min:8',
            'is_admin' => 'nullable|boolean',
            'can_manage_users' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        // Set admin flags based on role
        $role = $request->role;
        $isAdmin = $request->is_admin ?? false;
        $canManageUsers = $request->can_manage_users ?? false;

        // Auto-set admin flags for certain roles
        if (in_array($role, ['procurement', 'procurement_manager', 'executive', 'supply_chain_director', 'supply_chain', 'admin'])) {
            $isAdmin = true;
            $canManageUsers = true;
        }

        $newUser = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $role,
            'department' => $request->department,
            'is_admin' => $isAdmin,
            'can_manage_users' => $canManageUsers,
        ]);

        Log::info('User created by admin', [
            'created_by' => $user->id,
            'new_user_id' => $newUser->id,
            'new_user_email' => $newUser->email,
            'role' => $newUser->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => [
                'id' => $newUser->id,
                'name' => $newUser->name,
                'email' => $newUser->email,
                'role' => $newUser->role,
                'department' => $newUser->department,
                'is_admin' => $newUser->is_admin,
                'can_manage_users' => $newUser->can_manage_users,
            ]
        ], 201);
    }

    /**
     * Update user (admin only)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if (!$this->permissionService->canManageUsers($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to update users',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $targetUser = User::find($id);

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'role' => 'sometimes|in:employee,executive,procurement,procurement_manager,supply_chain_director,supply_chain,finance,finance_officer,admin',
            'department' => 'nullable|string|max:255',
            'password' => 'sometimes|string|min:8',
            'is_admin' => 'nullable|boolean',
            'can_manage_users' => 'nullable|boolean',
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

        if ($request->has('name')) {
            $updateData['name'] = $request->name;
        }

        if ($request->has('email')) {
            $updateData['email'] = $request->email;
        }

        if ($request->has('role')) {
            $updateData['role'] = $request->role;
            
            // Auto-set admin flags based on role
            $role = $request->role;
            if (in_array($role, ['procurement', 'procurement_manager', 'executive', 'supply_chain_director', 'supply_chain', 'admin'])) {
                $updateData['is_admin'] = true;
                $updateData['can_manage_users'] = true;
            }
        }

        if ($request->has('department')) {
            $updateData['department'] = $request->department;
        }

        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        if ($request->has('is_admin')) {
            $updateData['is_admin'] = $request->is_admin;
        }

        if ($request->has('can_manage_users')) {
            $updateData['can_manage_users'] = $request->can_manage_users;
        }

        $targetUser->update($updateData);

        Log::info('User updated by admin', [
            'updated_by' => $user->id,
            'target_user_id' => $targetUser->id,
            'changes' => $updateData,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => [
                'id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'role' => $targetUser->role,
                'department' => $targetUser->department,
                'is_admin' => $targetUser->is_admin,
                'can_manage_users' => $targetUser->can_manage_users,
            ]
        ]);
    }

    /**
     * Delete user (admin only)
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (!$this->permissionService->canManageUsers($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to delete users',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $targetUser = User::find($id);

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'error' => 'User not found',
                'code' => 'NOT_FOUND'
            ], 404);
        }

        // Prevent self-deletion
        if ($targetUser->id === $user->id) {
            return response()->json([
                'success' => false,
                'error' => 'You cannot delete your own account',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $targetUser->delete();

        Log::info('User deleted by admin', [
            'deleted_by' => $user->id,
            'deleted_user_id' => $id,
            'deleted_user_email' => $targetUser->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }
}
