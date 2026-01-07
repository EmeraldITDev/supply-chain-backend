<?php

namespace App\Services;

use App\Mail\VendorApprovalMail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class VendorApprovalService
{
    /**
     * Generate a secure temporary password
     *
     * @return string
     */
    public function generateTemporaryPassword(): string
    {
        // Generate a random 12-character password with mixed case, numbers, and special chars
        // Format: 3 uppercase + 3 lowercase + 3 numbers + 3 special chars
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // Exclude confusing characters
        $lowercase = 'abcdefghijkmnpqrstuvwxyz';
        $numbers = '23456789'; // Exclude 0 and 1
        $special = '!@#$%&*';
        
        $password = '';
        $password .= substr(str_shuffle($uppercase), 0, 3);
        $password .= substr(str_shuffle($lowercase), 0, 3);
        $password .= substr(str_shuffle($numbers), 0, 3);
        $password .= substr(str_shuffle($special), 0, 3);
        
        // Shuffle the final password
        return str_shuffle($password);
    }

    /**
     * Create a user account for an approved vendor
     *
     * @param VendorRegistration $registration
     * @param Vendor $vendor
     * @param string $temporaryPassword
     * @return User
     */
    public function createVendorUser(VendorRegistration $registration, Vendor $vendor, string $temporaryPassword): User
    {
        $user = User::create([
            'name' => $registration->contact_person,
            'email' => $registration->email,
            'password' => Hash::make($temporaryPassword),
            'role' => 'vendor',
            'vendor_id' => $vendor->id,
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);

        // Assign Spatie 'vendor' role if it exists
        try {
            $vendorRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
            $user->assignRole($vendorRole);
        } catch (\Exception $e) {
            // Log but don't fail if role assignment fails
            \Log::warning('Failed to assign vendor role to user: ' . $e->getMessage());
        }

        return $user;
    }

    /**
     * Send approval email with temporary password to vendor
     *
     * @param VendorRegistration $registration
     * @param string $temporaryPassword
     * @return void
     */
    public function sendApprovalEmail(VendorRegistration $registration, string $temporaryPassword): void
    {
        try {
            Mail::to($registration->email)
                ->send(new VendorApprovalMail($registration, $temporaryPassword));
        } catch (\Exception $e) {
            // Log error but don't fail the approval process
            \Log::error('Failed to send vendor approval email: ' . $e->getMessage());
        }
    }

    /**
     * Complete vendor approval process
     * Creates vendor, user account, generates password, and sends email
     *
     * @param VendorRegistration $registration
     * @param int $approvedBy User ID of the approver
     * @return array Array containing vendor, user, and temporary password
     */
    public function approveVendor(VendorRegistration $registration, int $approvedBy): array
    {
        // Generate temporary password
        $temporaryPassword = $this->generateTemporaryPassword();

        // Create vendor record from registration
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

        // Create user account
        $user = $this->createVendorUser($registration, $vendor, $temporaryPassword);

        // Update registration
        $registration->update([
            'status' => 'approved',
            'vendor_id' => $vendor->id,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
            'temp_password' => $temporaryPassword, // Store temporarily (not hashed) for reference
        ]);

        // Send approval email
        $this->sendApprovalEmail($registration, $temporaryPassword);

        return [
            'vendor' => $vendor,
            'user' => $user,
            'temporary_password' => $temporaryPassword,
        ];
    }
}

