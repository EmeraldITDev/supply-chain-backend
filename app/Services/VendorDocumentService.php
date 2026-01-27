<?php

namespace App\Services;

use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorDocumentService
{

    /**
     * Get the storage disk for vendor documents
     * Uses 'documents' disk from config, which can be configured per environment
     * Falls back to 'public' if S3 is requested but not available
     *
     * @return string
     */
    protected function getStorageDisk(): string
    {
        // Use 'public' for local development, 's3' for production
        // Can be overridden via DOCUMENTS_DISK env variable
        $disk = config('filesystems.documents_disk', env('DOCUMENTS_DISK', 'public'));
        
        // Safety check: If S3 is requested, verify it's available
        if ($disk === 's3') {
            // Check if Flysystem AWS S3 package is installed
            if (!class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
                \Log::warning('S3 disk requested but league/flysystem-aws-s3-v3 package not installed. Falling back to public disk. Run: composer require league/flysystem-aws-s3-v3');
                return 'public';
            }
            
            // Check if AWS credentials are configured
            $s3Config = config('filesystems.disks.s3');
            if (empty($s3Config) || empty($s3Config['key']) || empty($s3Config['secret'])) {
                \Log::warning('S3 disk requested but AWS credentials not configured. Falling back to public disk.');
                return 'public';
            }
            
            // Try to verify S3 connection is working (without actually connecting)
            // This will catch configuration errors early
            try {
                // Just check if Storage facade can resolve the disk
                // This will throw if S3 driver is misconfigured
                $testStorage = Storage::disk('s3');
                // Don't actually do anything, just verify the disk can be resolved
            } catch (\Exception $e) {
                \Log::warning('S3 disk requested but connection failed. Falling back to public disk.', [
                    'error' => $e->getMessage()
                ]);
                return 'public';
            }
        }
        
        return $disk;
    }

    /**
     * Store documents for a vendor registration
     * Stores files in storage and saves metadata to both JSON column and separate table
     *
     * @param VendorRegistration $registration
     * @param array $documents Array of UploadedFile objects
     * @return array Array of document metadata
     */
    public function storeDocuments(VendorRegistration $registration, array $documents): array
    {
        $storedDocuments = [];
        $documentMetadata = [];
        $disk = $this->getStorageDisk();

        \Log::info("Storing documents for vendor registration", [
            'registration_id' => $registration->id,
            'document_count' => count($documents),
            'disk' => $disk
        ]);

        // Sanitize company name for folder structure
        $companyName = Str::slug($registration->company_name ?? 'Vendor-' . $registration->id);
        $year = date('Y');

        foreach ($documents as $document) {
            if (!$document instanceof UploadedFile) {
                \Log::warning("Skipping non-file upload", ['type' => gettype($document)]);
                continue;
            }

            try {
                $originalName = $document->getClientOriginalName();
                $extension = $document->getClientOriginalExtension();
                $fileName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '_' . time() . '.' . $extension;

                $filePath = null;
                $fileUrl = null;
                $fileShareUrl = null;

                // Upload to storage (S3 or public)
                $disk = $this->getStorageDisk();
                $basePath = "vendor_documents/{$year}/{$companyName}";
                
                try {
                    $filePath = $document->storeAs($basePath, $fileName, $disk);
                } catch (\Exception $e) {
                    // If storage fails (e.g., S3 not available), fallback to public
                    if ($disk === 's3') {
                        \Log::warning('S3 storage failed, falling back to public disk', [
                            'error' => $e->getMessage()
                        ]);
                        $disk = 'public';
                        $filePath = $document->storeAs($basePath, $fileName, $disk);
                    } else {
                        throw $e; // Re-throw if it's not an S3 issue
                    }
                }

                if (!$filePath) {
                    \Log::error("Failed to store document", [
                        'file_name' => $originalName,
                        'disk' => $disk
                    ]);
                    continue;
                }

                // Verify file was actually stored
                if (!Storage::disk($disk)->exists($filePath)) {
                    \Log::error("File stored but doesn't exist in storage", [
                        'file_path' => $filePath,
                        'disk' => $disk
                    ]);
                    continue;
                    }

                // Get URL (temporary signed URL for S3, public URL for local)
                if ($disk === 's3') {
                    try {
                        $fileUrl = Storage::disk($disk)->temporaryUrl($filePath, now()->addHours(24));
                        $fileShareUrl = $fileUrl;
                    } catch (\Exception $e) {
                        \Log::warning('S3 temporary URL generation failed, using regular URL', [
                            'error' => $e->getMessage(),
                            'path' => $filePath
                        ]);
                        $fileUrl = Storage::disk($disk)->url($filePath);
                        $fileShareUrl = $fileUrl;
                    }
                } else {
                    $fileUrl = Storage::disk($disk)->url($filePath);
                    if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
                        $baseUrl = config('app.url');
                        $fileUrl = rtrim($baseUrl, '/') . '/' . ltrim($fileUrl, '/');
                    }
                    $fileShareUrl = $fileUrl;
                }

                // Ensure we have valid filePath and fileUrl before proceeding
                if (!$filePath || !$fileUrl) {
                    \Log::error("Failed to store document - missing path or URL", [
                        'file_name' => $originalName,
                        'registration_id' => $registration->id,
                        'disk' => $disk
                    ]);
                    continue;
                }

                // Get file metadata (same for both storage methods)
                $fileSize = $document->getSize();
                $fileType = $document->getMimeType();

                \Log::info("Document stored successfully", [
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'storage' => $disk,
                    'web_url' => $fileUrl,
                    'share_url' => $fileShareUrl,
                ]);

                // Store in separate table
                $documentRecord = VendorRegistrationDocument::create([
                    'vendor_registration_id' => $registration->id,
                    'file_path' => $filePath,
                    'file_name' => $originalName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'file_url' => $fileUrl,
                    'file_share_url' => $fileShareUrl,
                    'uploaded_at' => now(),
                ]);

                // Prepare metadata for JSON column
                $documentMetadata[] = [
                    'id' => $documentRecord->id,
                    'file_path' => $filePath,
                    'file_name' => $originalName,
                    'file_type' => $fileType,
                    'file_size' => $fileSize,
                    'file_url' => $fileUrl,
                    'file_share_url' => $fileShareUrl,
                    'uploaded_at' => $documentRecord->uploaded_at->toIso8601String(),
                ];

                $storedDocuments[] = $documentRecord;
            } catch (\Exception $e) {
                \Log::error("Error storing document", [
                    'file_name' => $originalName ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Update registration with document metadata in JSON column
        if (count($documentMetadata) > 0) {
            $registration->update([
                'documents' => $documentMetadata,
            ]);

            \Log::info("Updated registration with document metadata", [
                'registration_id' => $registration->id,
                'documents_stored' => count($documentMetadata)
            ]);
        } else {
            \Log::warning("No documents were stored successfully", [
                'registration_id' => $registration->id
            ]);
        }

        return $documentMetadata;
    }

    /**
     * Get document URL for public access
     * For S3, generates a temporary signed URL (valid for 1 hour)
     * For local storage, returns public URL
     *
     * @param string $filePath
     * @return string
     */
    public function getDocumentUrl(string $filePath, $documentId = null, $registrationId = null): string
    {
        $disk = $this->getStorageDisk();

        // For S3, generate temporary signed URL (valid for 1 hour)
        if ($disk === 's3') {
            try {
                return Storage::disk($disk)->temporaryUrl(
                    $filePath,
                    now()->addHour()
                );
            } catch (\Exception $e) {
                // If S3 fails, fallback to API download endpoint
                \Log::warning("S3 URL generation failed for {$filePath}: " . $e->getMessage());
                if ($registrationId && $documentId) {
                    return url("/api/vendors/registrations/{$registrationId}/documents/{$documentId}/download");
                }
                throw $e;
            }
        }

        // For local/public disk, check if file exists
        if (!Storage::disk($disk)->exists($filePath)) {
            // If file doesn't exist, use API download endpoint as fallback
            if ($registrationId && $documentId) {
                return url("/api/vendors/registrations/{$registrationId}/documents/{$documentId}/download");
            }
            // Try to extract from path or find in database
            if (preg_match('/vendor_documents\/(\d+)\//', $filePath, $matches)) {
                $regId = $matches[1];
                if (!$documentId) {
                    $document = VendorRegistrationDocument::where('file_path', $filePath)->first();
                    if ($document) {
                        return url("/api/vendors/registrations/{$regId}/documents/{$document->id}/download");
                    }
                } else {
                    return url("/api/vendors/registrations/{$regId}/documents/{$documentId}/download");
                }
            }
            throw new \Exception("File not found: {$filePath}");
        }

        // Return public URL for local storage
        return Storage::disk($disk)->url($filePath);
    }

    /**
     * Check if a document exists in storage
     *
     * @param string $filePath
     * @return bool
     */
    public function documentExists(string $filePath): bool
    {
        $disk = $this->getStorageDisk();
        return Storage::disk($disk)->exists($filePath);
    }

    /**
     * Get document content for download
     *
     * @param VendorRegistrationDocument $document
     * @return string|false
     */
    public function getDocumentContent(VendorRegistrationDocument $document)
    {
        $disk = $this->getStorageDisk();

        // Check if file exists
        if (!$document->file_path || !Storage::disk($disk)->exists($document->file_path)) {
            return false;
        }

        return Storage::disk($disk)->get($document->file_path);
    }

    /**
     * Delete a document
     *
     * @param VendorRegistrationDocument $document
     * @return bool
     */
    public function deleteDocument(VendorRegistrationDocument $document): bool
    {
        $disk = $this->getStorageDisk();

        // Delete file from storage
        if (Storage::disk($disk)->exists($document->file_path)) {
            Storage::disk($disk)->delete($document->file_path);
        }

        // Delete record from database
        return $document->delete();
    }
}

