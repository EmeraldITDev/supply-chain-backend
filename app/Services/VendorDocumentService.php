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
    protected ?OneDriveService $oneDriveService;

    public function __construct()
    {
        // Initialize OneDriveService if credentials are configured
        try {
            if (config('filesystems.disks.onedrive.client_id') && 
                config('filesystems.disks.onedrive.client_secret') &&
                config('filesystems.disks.onedrive.tenant_id')) {
                $this->oneDriveService = app(OneDriveService::class);
            } else {
                $this->oneDriveService = null;
            }
        } catch (\Exception $e) {
            Log::warning('OneDriveService initialization failed in VendorDocumentService', ['error' => $e->getMessage()]);
            $this->oneDriveService = null;
        }
    }

    /**
     * Get the storage disk for vendor documents
     * Uses 'documents' disk from config, which can be configured per environment
     *
     * @return string
     */
    protected function getStorageDisk(): string
    {
        // Use 'public' for local development, 's3' for production
        // Can be overridden via DOCUMENTS_DISK env variable
        return config('filesystems.documents_disk', env('DOCUMENTS_DISK', 'public'));
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
            'using_onedrive' => $this->oneDriveService !== null
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
                $useOneDrive = $this->oneDriveService !== null;
                
                // Use OneDrive if configured
                if ($useOneDrive) {
                    try {
                        // Upload to OneDrive in VendorDocuments folder (organized by year/company)
                        $folder = "VendorDocuments/{$year}/{$companyName}";
                        
                        $oneDriveResult = $this->oneDriveService->uploadFile($document, $folder, $fileName);
                        $filePath = $oneDriveResult['path']; // Store OneDrive path
                        $fileUrl = $oneDriveResult['webUrl'];
                        
                        // Create view-only sharing link
                        try {
                            $fileShareUrl = $this->oneDriveService->createSharingLink($oneDriveResult['path'], 'view');
                        } catch (\Exception $e) {
                            Log::warning('Failed to create sharing link for vendor document', [
                                'error' => $e->getMessage(),
                                'file' => $fileName
                            ]);
                            // Continue without sharing link
                        }
                        
                        \Log::info("Vendor document uploaded to OneDrive", [
                            'registration_id' => $registration->id,
                            'file_name' => $fileName,
                            'onedrive_path' => $filePath,
                            'web_url' => $fileUrl,
                            'share_url' => $fileShareUrl,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('OneDrive upload failed for vendor document, falling back to local storage', [
                            'error' => $e->getMessage(),
                            'file' => $fileName,
                            'registration_id' => $registration->id
                        ]);
                        $useOneDrive = false;
                    }
                }
                
                // Fallback to local/S3 storage if OneDrive is not configured or failed
                if (!$useOneDrive) {
                    $disk = $this->getStorageDisk();
                    $basePath = "vendor_documents/{$registration->id}";
                $filePath = $document->storeAs($basePath, $fileName, $disk);
                    $fileUrl = Storage::disk($disk)->url($filePath);

                if (!$filePath) {
                    \Log::error("Failed to store document", [
                        'file_name' => $originalName,
                        'disk' => $disk
                    ]);
                    continue;
                }

                    // Verify file was actually stored (only for local/S3)
                if (!Storage::disk($disk)->exists($filePath)) {
                    \Log::error("File stored but doesn't exist in storage", [
                        'file_path' => $filePath,
                        'disk' => $disk
                    ]);
                    continue;
                    }
                }
                
                // Ensure we have valid filePath and fileUrl before proceeding
                if (!$filePath || !$fileUrl) {
                    \Log::error("Failed to store document - missing path or URL", [
                        'file_name' => $originalName,
                        'registration_id' => $registration->id,
                        'use_onedrive' => $useOneDrive
                    ]);
                    continue;
                }

                // Get file metadata (same for both storage methods)
                $fileSize = $document->getSize();
                $fileType = $document->getMimeType();

                \Log::info("Document stored successfully", [
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'storage' => $useOneDrive ? 'onedrive' : $disk,
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
        // Check if document is stored in OneDrive
        if ($document->file_share_url && $this->oneDriveService) {
            try {
                // If we have a OneDrive share URL, redirect to it instead of downloading
                // For OneDrive files, we return a redirect response
                // The frontend should use the share URL directly
                return false; // Signal to use share URL instead
            } catch (\Exception $e) {
                Log::warning('Failed to access OneDrive document', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Fallback to local/S3 storage
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

