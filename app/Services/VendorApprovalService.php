<?php

namespace App\Services;

use App\Mail\VendorApprovalMail;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorRegistration;
use App\Services\VendorDocumentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VendorApprovalService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }
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
     * @throws \Exception If user already exists and is not a vendor
     */
    public function createVendorUser(VendorRegistration $registration, Vendor $vendor, string $temporaryPassword): User
    {
        // Check if user already exists
        $existingUser = User::where('email', $registration->email)->first();

        if ($existingUser) {
            // If user exists and is already a vendor, update the vendor_id and password
            if ($existingUser->role === 'vendor' || $existingUser->hasRole('vendor')) {
                $existingUser->update([
                    'vendor_id' => $vendor->id,
                    'password' => Hash::make($temporaryPassword),
                    'must_change_password' => true,
                    'password_changed_at' => null,
                ]);

                // Ensure Spatie role is assigned
                try {
                    $vendorRole = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'vendor', 'guard_name' => 'web']);
                    if (!$existingUser->hasRole('vendor')) {
                        $existingUser->assignRole($vendorRole);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to assign vendor role to existing user: ' . $e->getMessage());
                }

                return $existingUser;
            } else {
                // User exists but is not a vendor - this is an error
                throw new \Exception("A user with email {$registration->email} already exists and is not a vendor. Please contact support.");
            }
        }

        // Create new user
        try {
            $userData = [
                'name' => $registration->contact_person,
                'email' => $registration->email,
                'password' => Hash::make($temporaryPassword),
                'role' => 'vendor',
                'must_change_password' => true,
                'password_changed_at' => null,
            ];

            // Only add vendor_id if the column exists
            if (Schema::hasColumn('users', 'vendor_id')) {
                $userData['vendor_id'] = $vendor->id;
            }

            $user = User::create($userData);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle specific database errors
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                throw new \Exception("A user with email {$registration->email} already exists. Please contact support.");
            }
            \Log::error('Failed to create vendor user: ' . $e->getMessage(), [
                'registration_id' => $registration->id,
                'vendor_id' => $vendor->id,
                'email' => $registration->email,
            ]);
            throw new \Exception('Failed to create user account: ' . $e->getMessage());
        } catch (\Exception $e) {
            \Log::error('Failed to create vendor user: ' . $e->getMessage(), [
                'registration_id' => $registration->id,
                'vendor_id' => $vendor->id,
                'email' => $registration->email,
            ]);
            throw $e;
        }

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
            $emailService = app(EmailService::class);
            $emailService->sendVendorApprovalEmail(
                $registration->email,
                $registration->company_name,
                $temporaryPassword
            );
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
     * @throws \Exception If vendor already exists or registration already approved
     */
    public function approveVendor(VendorRegistration $registration, int $approvedBy): array
    {
        // Use database transaction to ensure atomicity
        return \DB::transaction(function () use ($registration, $approvedBy) {
            // Check if vendor already exists for this registration
            if ($registration->vendor_id) {
                $existingVendor = Vendor::find($registration->vendor_id);
                if ($existingVendor) {
                    throw new \Exception("This vendor registration has already been approved. Vendor ID: {$existingVendor->vendor_id}");
                }
            }

            // Generate temporary password
            $temporaryPassword = $this->generateTemporaryPassword();

            // Check if vendor with same email already exists
            $existingVendorByEmail = Vendor::where('email', $registration->email)->first();
            $vendor = null;

            if ($existingVendorByEmail) {
                // Use existing vendor
                $vendor = $existingVendorByEmail;
                // Update vendor details from registration if needed
                $vendor->update([
                    'name' => $registration->company_name,
                    'category' => $registration->category,
                    'phone' => $registration->phone,
                    'address' => $registration->address,
                    'tax_id' => $registration->tax_id,
                    'contact_person' => $registration->contact_person,
                    'status' => 'Active',
                    // Extended profile fields from registration
                    'year_established' => $registration->year_established,
                    'number_of_employees' => $registration->number_of_employees,
                    'annual_revenue' => $registration->annual_revenue,
                    'website' => $registration->website,
                    'country_code' => $registration->country_code,
                    'contact_person_email' => $registration->contact_person_email,
                    'contact_person_phone' => $registration->contact_person_phone,
                    'contact_person_title' => $registration->contact_person_title,
                    'city' => $registration->city,
                    'state' => $registration->state,
                    'postal_code' => $registration->postal_code,
                    'alternate_phone' => $registration->alternate_phone,
                    'bank_name' => $registration->bank_name,
                    'account_name' => $registration->account_name,
                    'currency' => $registration->currency,
                ]);
            } else {
                // Create new vendor record from registration
                try {
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
                        // Extended profile fields from registration
                        'year_established' => $registration->year_established,
                        'number_of_employees' => $registration->number_of_employees,
                        'annual_revenue' => $registration->annual_revenue,
                        'website' => $registration->website,
                        'country_code' => $registration->country_code,
                        'contact_person_email' => $registration->contact_person_email,
                        'contact_person_phone' => $registration->contact_person_phone,
                        'contact_person_title' => $registration->contact_person_title,
                        'city' => $registration->city,
                        'state' => $registration->state,
                        'postal_code' => $registration->postal_code,
                        'alternate_phone' => $registration->alternate_phone,
                        'bank_name' => $registration->bank_name,
                        'account_name' => $registration->account_name,
                        'currency' => $registration->currency,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to create vendor: ' . $e->getMessage(), [
                        'registration_id' => $registration->id,
                        'email' => $registration->email,
                        'trace' => $e->getTraceAsString(),
                    ]);
                    throw new \Exception('Failed to create vendor record: ' . $e->getMessage());
                }
            }

            // Transfer documents from registration to vendor — runs for both new and existing vendors
          if ($registration->documents && count($registration->documents) > 0) {
            foreach ($registration->documents as $doc) {
                $vendor->documents()->updateOrCreate(
                    ['file_path' => $doc['file_path']],
                    [
                        'file_name'      => $doc['file_name'] ?? null,
                        'file_type'      => $doc['file_type'] ?? null,
                        'file_size'      => $doc['file_size'] ?? null,
                        'file_path'      => $doc['file_path'],
                        'file_url'       => $doc['file_url'] ?? null,
                        'file_share_url' => $doc['file_share_url'] ?? null,
                        'uploaded_at'    => $doc['uploaded_at'] ?? null,
                    ]
                );
            }
        }

            // Create or update user account
            $user = $this->createVendorUser($registration, $vendor, $temporaryPassword);

            // Update registration
            try {
                $registration->update([
                    'status' => VendorRegistration::STATUS_APPROVED,
                    'vendor_id' => $vendor->id,
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                    'temp_password' => $temporaryPassword,
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to update registration: ' . $e->getMessage(), [
                    'registration_id' => $registration->id,
                    'vendor_id' => $vendor->id,
                    'trace' => $e->getTraceAsString(),
                ]);
                throw new \Exception('Failed to update registration: ' . $e->getMessage());
            }

            // Send approval email (outside transaction to avoid rollback on email failure)
            try {
                $this->sendApprovalEmail($registration, $temporaryPassword);
            } catch (\Exception $e) {
                \Log::warning('Failed to send approval email, but vendor was approved: ' . $e->getMessage());
            }

            // Move documents from registration folder to vendor-specific permanent folder
            try {
                $documentService = app(VendorDocumentService::class);
                $movedDocuments = $documentService->moveDocumentsToVendorFolder($registration, $vendor);
                \Log::info('Documents moved to vendor folder after approval', [
                    'registration_id' => $registration->id,
                    'vendor_id' => $vendor->id,
                    'moved_count' => count($movedDocuments)
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to move documents to vendor folder: ' . $e->getMessage(), [
                    'registration_id' => $registration->id,
                    'vendor_id' => $vendor->id,
                    'trace' => $e->getTraceAsString()
                ]);
            }

            // Send notification to procurement team
            try {
                $this->notificationService->notifyVendorApproved($vendor, $temporaryPassword);
            } catch (\Exception $e) {
                \Log::warning('Failed to send vendor approval notification: ' . $e->getMessage());
            }

            return [
                'vendor' => $vendor,
                'user' => $user,
                'temporary_password' => $temporaryPassword,
            ];
        });
    }

}

