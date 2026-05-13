<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PermissionService;
use App\Support\SignatureUrls;
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
     * Get all users (user management roles + logistics for internal directory).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->permissionService->canListUsersDirectory($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to view the user directory',
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
                'designated_requisition_creator' => (bool) $user->designated_requisition_creator,
                'signature_image_path' => $user->signature_image_path,
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
     * Canonical role keys allowed for create/update.
     * The frontend may send either canonical keys (`logistics_manager`) or
     * human labels (`Logistics Manager`); we normalise before validation.
     */
    private const ALLOWED_ROLES = [
        'admin',
        'employee',
        'executive',
        'procurement_manager',
        'supply_chain_director',
        'finance',
        'chairman',
        'logistics',           // legacy catch-all
        'logistics_manager',
        'logistics_officer',
        'vendor',
    ];

    /**
     * Map UI labels / legacy values to canonical role keys.
     */
    private function normaliseRole(?string $role): ?string
    {
        if ($role === null) {
            return null;
        }
        $trimmed = trim($role);
        if ($trimmed === '') {
            return null;
        }
        $key = strtolower(str_replace([' ', '-'], ['_', '_'], $trimmed));

        $map = [
            'logistics_manager' => 'logistics_manager',
            'logistics_officer' => 'logistics_officer',
            'logistics' => 'logistics_manager', // legacy → manager (department-wide access)
            'procurement_manager' => 'procurement_manager',
            'procurement' => 'procurement_manager',
            'supply_chain_director' => 'supply_chain_director',
            'supply_chain' => 'supply_chain_director',
            'finance_officer' => 'finance',
            'finance' => 'finance',
        ];

        if (isset($map[$key])) {
            return $map[$key];
        }

        return in_array($key, self::ALLOWED_ROLES, true) ? $key : $trimmed;
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

        if ($request->has('role')) {
            $request->merge(['role' => $this->normaliseRole((string) $request->input('role'))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:' . implode(',', self::ALLOWED_ROLES),
            'department' => 'nullable|string|max:255',
            'password' => 'required|string|min:8',
            'is_admin' => 'nullable|boolean',
            'can_manage_users' => 'nullable|boolean',
            'designated_requisition_creator' => 'nullable|boolean',
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
            'designated_requisition_creator' => (bool) $request->boolean('designated_requisition_creator', false),
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
                'designated_requisition_creator' => (bool) $newUser->designated_requisition_creator,
                'signature_image_path' => $newUser->signature_image_path,
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

        if ($request->has('role')) {
            $request->merge(['role' => $this->normaliseRole((string) $request->input('role'))]);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'role' => 'required|in:' . implode(',', self::ALLOWED_ROLES),
            'department' => 'nullable|string|max:255',
            'password' => 'sometimes|string|min:8',
            'is_admin' => 'nullable|boolean',
            'can_manage_users' => 'nullable|boolean',
            'designated_requisition_creator' => 'nullable|boolean',
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
        if ($request->has('designated_requisition_creator')) {
            $updateData['designated_requisition_creator'] = (bool) $request->designated_requisition_creator;
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
                'designated_requisition_creator' => (bool) $targetUser->designated_requisition_creator,
                'signature_image_path' => $targetUser->signature_image_path,
                'is_admin' => $targetUser->is_admin,
                'can_manage_users' => $targetUser->can_manage_users,
            ]
        ]);
    }

    public function assignRequisitionCreator(Request $request, string $department)
    {
        $user = $request->user();
        if (!$this->permissionService->canManageUsers($user)) {
            return response()->json([
                'success' => false,
                'error' => 'You do not have permission to assign requisition creators',
                'code' => 'FORBIDDEN'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        $targetUser = User::find($request->integer('user_id'));
        if (!$targetUser || strcasecmp((string) $targetUser->department, (string) $department) !== 0) {
            return response()->json([
                'success' => false,
                'error' => 'Selected user must belong to the requested department.',
                'code' => 'VALIDATION_ERROR'
            ], 422);
        }

        User::whereRaw('LOWER(department) = ?', [strtolower($department)])
            ->update(['designated_requisition_creator' => false]);

        $targetUser->update(['designated_requisition_creator' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Designated requisition creator updated.',
            'data' => [
                'department' => $department,
                'designated_creator' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'department' => $targetUser->department,
                    'designated_requisition_creator' => (bool) $targetUser->designated_requisition_creator,
                ],
            ],
        ]);
    }

    public function uploadSignature(Request $request, int $id)
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $isAdmin = $this->permissionService->canManageUsers($actor) || $actor->role === 'admin';
        if (!$isAdmin && $actor->id !== $id) {
            return response()->json([
                'success' => false,
                'error' => 'You are not authorised to upload this signature.',
            ], 403);
        }

        $target = User::find($id);
        if (!$target) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'signature' => 'nullable|file|image|max:5120',
            'signature_base64' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$request->hasFile('signature') && !$request->filled('signature_base64')) {
            return response()->json([
                'success' => false,
                'error' => 'Provide either signature file upload or signature_base64.',
            ], 422);
        }

        // Persist to the signatures disk (defaults to the `public` disk so
        // the Settings page can render a preview without an admin grant).
        $disk = config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
        $path = null;

        if ($request->hasFile('signature')) {
            $file = $request->file('signature');
            $filename = 'signature_' . $target->id . '_' . time() . '.' . ($file->getClientOriginalExtension() ?: 'png');
            $path = 'signatures/' . $filename;
            \Storage::disk($disk)->putFileAs('signatures', $file, $filename);
        } else {
            $raw = (string) $request->input('signature_base64');
            $payload = preg_replace('/^data:image\/\w+;base64,/', '', $raw) ?: '';
            $binary = base64_decode($payload, true);
            if ($binary === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid base64 signature payload.',
                ], 422);
            }
            $filename = 'signature_' . $target->id . '_' . time() . '.png';
            $path = 'signatures/' . $filename;
            \Storage::disk($disk)->put($path, $binary);
        }

        $target->update(['signature_image_path' => $path]);

        $target->refresh();
        $signatureUrl = SignatureUrls::forUser($target);

        return response()->json([
            'success' => true,
            'message' => 'Signature uploaded successfully.',
            'data' => [
                'user_id' => $target->id,
                'signature_image_path' => $target->signature_image_path,
                'signatureImagePath' => $target->signature_image_path,
                'signature_url' => $signatureUrl,
                'signatureUrl' => $signatureUrl,
                'has_signature' => true,
                'hasSignature' => true,
            ],
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
