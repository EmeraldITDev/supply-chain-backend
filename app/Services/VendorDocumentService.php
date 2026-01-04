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
            $filePath = $document->storeAs($basePath, $fileName, 'public');

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
     *
     * @param string $filePath
     * @return string
     */
    public function getDocumentUrl(string $filePath): string
    {
        return Storage::disk('public')->url($filePath);
    }

    /**
     * Delete a document
     *
     * @param VendorRegistrationDocument $document
     * @return bool
     */
    public function deleteDocument(VendorRegistrationDocument $document): bool
    {
        // Delete file from storage
        if (Storage::disk('public')->exists($document->file_path)) {
            Storage::disk('public')->delete($document->file_path);
        }

        // Delete record from database
        return $document->delete();
    }
}

