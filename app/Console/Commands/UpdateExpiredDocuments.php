<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\VendorRegistrationDocument;
use App\Models\VendorRegistration;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class UpdateExpiredDocuments extends Command
{
    protected $signature = 'documents:mark-expired';
    protected $description = 'Mark vendor registration documents as expired if past expiry date';

    public function handle()
    {
        $this->info('=== Starting Document Expiry Check ===');
        $this->line('Current time: ' . Carbon::now()->toDateTimeString());

        try {
            // Step 1: Mark documents as expired if expiry_date has passed
            $updated = VendorRegistrationDocument::where('status', 'Approved')
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<', Carbon::now())
                ->update(['status' => 'Expired']);
            
            $this->info("✅ Updated {$updated} documents to Expired status");

            // Step 2: Handle vendor registrations with expired required documents
            $this->handleExpiredVendorRegistrations();

            $this->info('=== Document Expiry Check Complete ===');
            
        } catch (\Exception $e) {
            $this->error('❌ Error during expiry check: ' . $e->getMessage());
            \Log::error('UpdateExpiredDocuments command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function handleExpiredVendorRegistrations()
    {
        try {
            // Get all approved registrations
            $registrations = VendorRegistration::where('status', 'Approved')
                ->get();
            
            $this->line("Checking {$registrations->count()} approved registrations...");

            foreach ($registrations as $registration) {
                // Check if has expired required documents
                $hasExpiredRequiredDoc = $registration->documents()
                    ->where('is_required', true)
                    ->where('status', 'Expired')
                    ->exists();
                
                if ($hasExpiredRequiredDoc) {
                    $this->line("⚠️  Registration {$registration->id} ({$registration->company_name}) has expired required docs");
                    
                    // Update registration status
                    $registration->update([
                        'status' => 'Documents Incomplete',
                        'review_notes' => 'One or more required documents have expired. Please upload renewed documents.',
                    ]);

                    // Send notification email to vendor
                    try {
                        $this->sendExpiredDocumentNotification($registration);
                    } catch (\Exception $e) {
                        $this->warn("   Email failed for {$registration->email}: " . $e->getMessage());
                    }

                    $this->line("   ✅ Updated registration status to Documents Incomplete");
                }
            }

        } catch (\Exception $e) {
            $this->error('Error handling expired vendor registrations: ' . $e->getMessage());
            throw $e;
        }
    }

    private function sendExpiredDocumentNotification(VendorRegistration $registration)
    {
        // Get expired required documents for the email
        $expiredDocs = $registration->documents()
            ->where('is_required', true)
            ->where('status', 'Expired')
            ->get();

        // Prepare email data
        $mailData = [
            'vendorName' => $registration->company_name,
            'registration_id' => $registration->id,
            'expiredDocuments' => $expiredDocs->map(function ($doc) {
                return [
                    'document_type' => $doc->type,
                    'expiry_date' => $doc->expiry_date->format('Y-m-d'),
                    'file_name' => $doc->file_name,
                ];
            })->toArray(),
            'registrationStatus' => $registration->status,
            'dashboardUrl' => config('app.frontend_url', config('app.url')) . '/vendor/documents',
        ];

        // Send email
        Mail::send('emails.expired-documents', $mailData, function ($message) use ($registration) {
            $message->to($registration->email)
                    ->subject('Action Required: Your Vendor Documents Have Expired')
                    ->from(config('mail.from.address'), config('mail.from.name'));
        });

        $this->line("   📧 Expiry notification sent to {$registration->email}");
    }
}
