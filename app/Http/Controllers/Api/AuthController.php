<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use App\Support\DepartmentMatcher;
use App\Support\SignatureUrls;
use App\Support\UserRoleNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Check if user has access to supply chain system
     */
    private function hasSupplyChainAccess(User $user): bool
    {
        return UserRoleNormalizer::hasSupplyChainLoginAccess($user);
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

        $user = User::findByEmailCaseInsensitive($request->email);

        if ($user) {
            $user->loadMissing('employee');
        }

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (UserRoleNormalizer::isVendorAccount($user)) {
            throw ValidationException::withMessages([
                'email' => ['Vendor accounts must sign in through the vendor portal.'],
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

        // SCM permissions use supply_chain_role exclusively
        $scmRole = $user->getRoleNames()->first() ?? $user->scmRole() ?? 'employee';
        
        // Get department from employee or user
        $department = $user->employee->department ?? $user->department ?? null;

        // Check if user must change password
        $requiresPasswordChange = $user->must_change_password ?? false;

        $signatureUrl = SignatureUrls::forUser($user);

        return response()->json([
            'user' => $this->authUserPayload($user, $scmRole, $department, $signatureUrl),
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

        // SCM permissions use supply_chain_role exclusively
        $scmRole = $user->getRoleNames()->first() ?? $user->scmRole() ?? 'employee';

        // Get department from employee or user
        $department = $user->employee->department ?? $user->department ?? null;

        // Resolve a public URL for the signature image if one is uploaded so
        // the Settings page can render an instant preview without needing a
        // separate endpoint.
        $signatureUrl = SignatureUrls::forUser($user);

        return response()->json(array_merge(
            $this->authUserPayload($user, $scmRole, $department, $signatureUrl),
            [
                'requiresPasswordChange' => $user->must_change_password ?? false,
                'tokenExpiresAt' => $token->expires_at ? $token->expires_at->toIso8601String() : null,
            ]
        ));
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
            $department = DepartmentMatcher::storageLabel($request->department);
            $updateData['department'] = $department;
        }

        // If user has employee record, update it too
        if ($user->employee_id) {
            $employee = \App\Models\Employee::find($user->employee_id);
            if ($employee) {
                if ($request->has('name')) {
                    $employee->update(['full_name' => $request->name]);
                }
                if ($request->has('department')) {
                    $employee->update(['department' => $updateData['department'] ?? DepartmentMatcher::storageLabel($request->department)]);
                }
            }
        }

        $user->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => array_merge(
                [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'department' => $user->department,
                ],
                [
                    'supply_chain_role' => $user->scmRole(),
                    'hris_role' => $user->hris_role,
                    'role' => $user->scmRole(),
                ]
            )
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

        $user = User::findVendorPortalUserByEmail($request->email);

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

    /**
     * Disk used for signature uploads. Falls back to the local disk so a
     * fresh install works without S3 credentials.
     */
    private function signatureDisk(): string
    {
        return config('filesystems.signatures_disk', env('SIGNATURES_DISK', 'public'));
    }

    /**
     * Self-service signature upload. Anyone who signs Purchase Orders / JCCs
     * can upload their own image (PNG/JPG/GIF/WEBP, transparent background
     * recommended) without needing an admin to do it for them.
     */
    public function uploadOwnSignature(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'signature' => 'nullable|file|image|max:5120',
            'file' => 'nullable|file|image|max:5120',
            'image' => 'nullable|file|image|max:5120',
            'signature_base64' => 'nullable|string',
            'image_base64' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $file = $request->file('signature') ?? $request->file('file') ?? $request->file('image');
        $base64 = $request->input('signature_base64') ?? $request->input('image_base64');

        if (!$file && empty($base64)) {
            return response()->json([
                'success' => false,
                'error' => 'Provide a signature image file or base64 payload.',
                'code' => 'VALIDATION_ERROR',
            ], 422);
        }

        $disk = $this->signatureDisk();
        $previous = $user->signature_image_path;

        if ($file) {
            $filename = sprintf('signature_%d_%d.%s', $user->id, time(), $file->getClientOriginalExtension() ?: 'png');
            $path = 'signatures/' . $filename;
            Storage::disk($disk)->putFileAs('signatures', $file, $filename);
        } else {
            $payload = preg_replace('/^data:image\/\w+;base64,/', '', (string) $base64) ?: '';
            $binary = base64_decode($payload, true);
            if ($binary === false || $binary === '') {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid base64 signature payload.',
                    'code' => 'VALIDATION_ERROR',
                ], 422);
            }
            $filename = sprintf('signature_%d_%d.png', $user->id, time());
            $path = 'signatures/' . $filename;
            Storage::disk($disk)->put($path, $binary);
        }

        $user->update(['signature_image_path' => $path]);

        // Best-effort cleanup of the prior signature image.
        if ($previous && $previous !== $path) {
            try {
                Storage::disk($disk)->delete($previous);
            } catch (\Throwable) {
                // ignore
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Signature uploaded successfully.',
            'data' => [
                'user_id' => $user->id,
                'signature_image_path' => $user->signature_image_path,
                'signatureImagePath' => $user->signature_image_path,
                'signature_url' => SignatureUrls::forUser($user),
                'signatureUrl' => SignatureUrls::forUser($user),
                'has_signature' => true,
                'hasSignature' => true,
            ],
        ]);
    }

    /**
     * Returns a preview URL for the authenticated user's signature, if any.
     */
    public function getOwnSignature(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'signature_image_path' => $user->signature_image_path,
                'signatureImagePath' => $user->signature_image_path,
                'signature_url' => SignatureUrls::forUser($user),
                'signatureUrl' => SignatureUrls::forUser($user),
                'has_signature' => !empty($user->signature_image_path),
                'hasSignature' => !empty($user->signature_image_path),
            ],
        ]);
    }

    /**
     * Clears the authenticated user's signature.
     */
    public function deleteOwnSignature(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication required.',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $path = $user->signature_image_path;
        if ($path) {
            try {
                Storage::disk($this->signatureDisk())->delete($path);
            } catch (\Throwable) {
                // ignore
            }
        }
        $user->update(['signature_image_path' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Signature removed.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function authUserPayload(User $user, string $scmRole, ?string $department, ?string $signatureUrl): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'supply_chain_role' => $scmRole,
            'hris_role' => $user->hris_role,
            'role' => $scmRole,
            'department' => $department,
            'employeeId' => $user->employee_id,
            'createdAt' => $user->created_at->toIso8601String(),
            'signature_image_path' => $user->signature_image_path,
            'signatureImagePath' => $user->signature_image_path,
            'signature_url' => $signatureUrl,
            'signatureUrl' => $signatureUrl,
            'has_signature' => ! empty($user->signature_image_path),
            'hasSignature' => ! empty($user->signature_image_path),
        ];
    }
}
