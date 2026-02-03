<?php

namespace App\Http\Controllers\Api\V1\Logistics;

use App\Http\Controllers\Api\AuthController as CoreAuthController;
use App\Http\Requests\Logistics\VendorAcceptRequest;
use App\Http\Requests\Logistics\VendorInviteRequest;
use App\Models\Logistics\VendorInvite;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Logistics\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends ApiController
{
    public function __construct(private AuditLogger $auditLogger)
    {
    }

    public function login(Request $request)
    {
        return app(CoreAuthController::class)->login($request);
    }

    public function me(Request $request)
    {
        return app(CoreAuthController::class)->me($request);
    }

    public function vendorInvite(VendorInviteRequest $request)
    {
        $token = Str::random(40);
        $expiresAt = now()->addDays($request->integer('expires_in_days', 7));

        $invite = VendorInvite::create([
            'email' => $request->email,
            'vendor_name' => $request->vendor_name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_by' => $request->user()?->id,
            'metadata' => $request->input('metadata', []),
        ]);

        $this->auditLogger->log(
            'vendor_invite_created',
            $request->user(),
            'vendor_invite',
            (string) $invite->id,
            ['email' => $invite->email, 'expires_at' => $expiresAt->toIso8601String()],
            $request
        );

        $response = [
            'invite_id' => $invite->id,
            'email' => $invite->email,
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        if (config('app.debug')) {
            $response['token'] = $token;
        }

        return $this->success($response, 201);
    }

    public function vendorAccept(VendorAcceptRequest $request)
    {
        $tokenHash = hash('sha256', $request->token);

        $invite = VendorInvite::where('email', $request->email)
            ->where('token_hash', $tokenHash)
            ->whereNull('accepted_at')
            ->first();

        if (!$invite || $invite->expires_at->isPast()) {
            return $this->error('Invalid or expired invitation', 'INVALID_INVITE', 422);
        }

        $vendor = Vendor::firstOrCreate([
            'email' => $request->email,
        ], [
            'vendor_id' => Vendor::generateVendorId(),
            'name' => $request->vendor_name,
            'status' => 'temporary',
            'phone' => $request->phone,
            'address' => $request->address,
            'contact_person' => $request->contact_person,
        ]);

        $user = User::firstOrCreate([
            'email' => $request->email,
        ], [
            'name' => $request->vendor_name,
            'password' => Hash::make(Str::random(24)),
            'role' => 'vendor',
            'vendor_id' => $vendor->id,
        ]);

        if (method_exists($user, 'assignRole')) {
            $user->assignRole('vendor');
        }

        $expiresAt = now()->addDays(7);
        $token = $user->createToken('vendor-invite-token', ['*'], $expiresAt)->plainTextToken;

        $invite->accepted_at = now();
        $invite->save();

        $this->auditLogger->log(
            'vendor_invite_accepted',
            $user,
            'vendor',
            (string) $vendor->id,
            ['email' => $vendor->email],
            $request
        );

        return $this->success([
            'vendor' => [
                'id' => $vendor->vendor_id,
                'name' => $vendor->name,
                'email' => $vendor->email,
                'status' => $vendor->status,
            ],
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }
}
