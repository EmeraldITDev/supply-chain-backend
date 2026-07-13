<?php

namespace Tests\Unit;

use App\Models\ProcurementDocument;
use App\Services\ProcurementDocumentService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcurementDocumentFreshUrlTest extends TestCase
{
    public function test_transform_prefers_fresh_signed_url_over_stale_stored_url(): void
    {
        Storage::fake('s3');
        config(['filesystems.documents_disk' => 's3']);

        $path = 'procurement-documents/2026/07/MRF-1/doc.pdf';
        Storage::disk('s3')->put($path, 'pdf-bytes');

        $document = new ProcurementDocument([
            'id' => 1,
            'mrf_id' => 10,
            'vendor_id' => null,
            'type' => ProcurementDocument::TYPE_GRN,
            'file_name' => 'doc.pdf',
            'file_path' => $path,
            'file_url' => 'https://expired.example/request-has-expired',
            'uploaded_by' => null,
            'uploaded_at' => now(),
            'version' => 1,
            'is_active' => true,
            'metadata' => null,
        ]);

        $payload = app(ProcurementDocumentService::class)->transform($document);

        $this->assertNotSame('https://expired.example/request-has-expired', $payload['fileUrl'] ?? null);
        $this->assertNotSame('https://expired.example/request-has-expired', $payload['file_url'] ?? null);
        $this->assertNotEmpty($payload['fileUrl'] ?? $payload['file_url'] ?? null);
    }
}
