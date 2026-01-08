<?php

namespace App\Services;

use App\Models\VendorRegistration;
use App\Models\VendorRegistrationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VendorDocumentService
{
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

        // Create directory for this registration
        $basePath = "vendor_documents/{$registration->id}";

        foreach ($documents as $document) {
            if (!$document instanceof UploadedFile) {
                continue;
            }

            // Generate unique filename
            $originalName = $document->getClientOriginalName();
            $extension = $document->getClientOriginalExtension();
            $fileName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '_' . time() . '.' . $extension;
            
            // Store file in configured disk (S3 in production, local in development)
            $filePath = $document->storeAs($basePath, $fileName, $disk);

            // Get file metadata
            $fileSize = $document->getSize();
            $fileType = $document->getMimeType();

            // Store in separate table
            $documentRecord = VendorRegistrationDocument::create([
                'vendor_registration_id' => $registration->id,
                'file_path' => $filePath,
                'file_name' => $originalName,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'uploaded_at' => now(),
            ]);

            // Prepare metadata for JSON column
            $documentMetadata[] = [
                'id' => $documentRecord->id,
                'file_path' => $filePath,
                'file_name' => $originalName,
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'uploaded_at' => $documentRecord->uploaded_at->toIso8601String(),
            ];

            $storedDocuments[] = $documentRecord;
        }

        // Update registration with document metadata in JSON column
        $registration->update([
            'documents' => $documentMetadata,
        ]);

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
        
        if (!Storage::disk($disk)->exists($document->file_path)) {
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

