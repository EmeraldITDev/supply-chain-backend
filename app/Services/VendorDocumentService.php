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
        return config('filesystems.documents_disk', 's3');
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
    public function getDocumentUrl(string $filePath): string
    {
        $disk = $this->getStorageDisk();
        
        // For S3, generate temporary signed URL (valid for 1 hour)
        if ($disk === 's3') {
            return Storage::disk($disk)->temporaryUrl(
                $filePath,
                now()->addHour()
            );
        }
        
        // For local/public disk, return regular URL
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

