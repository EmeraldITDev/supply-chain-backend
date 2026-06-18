<?php

namespace Tests\Unit;

use App\Models\Logistics\JobCompletionCertificate;
use App\Models\Logistics\Trip;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GrnPdfService;
use App\Services\Logistics\JCCReferenceNumberService;
use App\Services\Logistics\JobCompletionCertificateService;
use Tests\TestCase;

class DocumentDisplayPayloadTest extends TestCase
{
    public function test_jcc_reference_number_uses_trip_year_sequence_format(): void
    {
        $service = app(JCCReferenceNumberService::class);
        $trip = new Trip(['id' => 42, 'trip_code' => 'TRIP-SERVIZO-001']);

        $this->assertMatchesRegularExpression(
            '/^JCC-TRIP-SERVIZO-001-' . now()->format('Y') . '-\d{3}$/',
            $service->generateReferenceNumberForTrip($trip)
        );
    }

    public function test_grn_prefill_payload_includes_vendor_po_and_line_items(): void
    {
        $service = app(GrnPdfService::class);
        $mrf = new \App\Models\MRF([
            'mrf_id' => 'MRF-OANDO-PRC-MAIN-2026-029',
            'formatted_id' => 'MRF-OANDO-PRC-MAIN-2026-029',
            'title' => 'Laptop procurement',
            'department' => 'IT',
            'ship_to_address' => 'Lagos warehouse',
            'po_number' => 'PO-2026-001',
        ]);
        $mrf->setRelation('items', collect([
            new \App\Models\MRFItem([
                'item_name' => 'HP Elitebook 840 G5',
                'description' => 'Laptop',
                'quantity' => 1,
                'unit' => 'Pack',
                'unit_price' => 470000,
            ]),
        ]));
        $mrf->setRelation('selectedVendor', new Vendor([
            'name' => 'Enirol Technology',
            'address' => 'Aa plaza',
            'contact_person' => 'John Doe',
            'phone' => '08000000000',
        ]));

        $payload = $service->buildPrefillPayload($mrf);

        $this->assertArrayHasKey('grnNumber', $payload);
        $this->assertSame('Enirol Technology', $payload['vendor']['name']);
        $this->assertSame('PO-2026-001', $payload['po']['poNumber']);
        $this->assertSame('NGN', $payload['po']['currency']);
        $this->assertNotEmpty($payload['lineItems']);
        $this->assertArrayHasKey('warehouse', $payload['authorisedSignatories']);
    }

    public function test_grn_prefill_uses_price_comparison_rows_when_mrf_items_empty(): void
    {
        $service = app(GrnPdfService::class);
        $vendor = new Vendor([
            'id' => 99,
            'name' => 'Fleet Vendor Ltd',
            'vendor_name' => 'Fleet Vendor Ltd',
            'address' => '12 Marina Road',
        ]);

        $mrf = new \App\Models\MRF([
            'id' => 6,
            'mrf_id' => 'MRF-EMERALD-2026-006',
            'formatted_id' => 'MRF-EMERALD-2026-006',
            'title' => 'Fleet service',
            'category' => 'services',
            'po_number' => 'PO-2026-0617-115051-26006',
        ]);
        $mrf->setRelation('items', collect());
        $mrf->setRelation('selectedVendor', null);
        $mrf->setRelation('priceComparisons', collect([
            new \App\Models\PriceComparison([
                'vendor_id' => 99,
                'item_description' => 'Vehicle servicing',
                'unit_price' => 150000,
                'quantity' => 1,
                'total_price' => 150000,
                'is_selected' => true,
            ]),
        ]));
        $mrf->priceComparisons->first()->setRelation('vendor', $vendor);

        $payload = $service->buildPrefillPayload($mrf);

        $this->assertSame('Fleet Vendor Ltd', $payload['vendor']['name']);
        $this->assertSame('Fleet Vendor Ltd', $payload['supplier']['name']);
        $this->assertCount(1, $payload['lineItems']);
        $this->assertSame('Vehicle servicing', $payload['lineItems'][0]['description']);
    }

    public function test_jcc_display_record_exposes_pdf_fields(): void
    {
        $service = app(JobCompletionCertificateService::class);
        $vendor = new Vendor([
            'id' => 7,
            'name' => 'Servizo Logistics',
            'address' => 'Abuja',
            'contact_person' => 'Jane Vendor',
            'phone' => '08011111111',
            'email' => 'vendor@example.com',
        ]);
        $trip = new Trip([
            'id' => 10,
            'trip_code' => 'TRIP-SERVIZO-001',
            'origin' => 'Lagos',
            'destination' => 'Abuja',
            'purpose' => 'Personnel movement',
            'po_number' => 'PO-TRIP-001',
        ]);
        $trip->setRelation('vendor', $vendor);
        $trip->setRelation('selectedVendor', $vendor);

        $jcc = new JobCompletionCertificate([
            'id' => '11111111-1111-1111-1111-111111111111',
            'reference_number' => 'JCC-TRIP-SERVIZO-001-2026-001',
            'status' => JobCompletionCertificate::STATUS_DRAFT,
            'certification_text' => 'Certified complete.',
            'currency' => 'NGN',
            'subtotal' => 100000,
            'vat' => 7500,
            'total_amount' => 107500,
            'date_issued' => now()->toDateString(),
        ]);
        $jcc->setRelation('trip', $trip);
        $jcc->setRelation('vendor', $vendor);
        $jcc->setRelation('lineItems', collect());
        $jcc->created_at = now();
        $jcc->updated_at = now();

        $payload = $service->toDisplayRecord($jcc);

        $this->assertSame('JCC-TRIP-SERVIZO-001-2026-001', $payload['referenceNumber']);
        $this->assertSame('Certified complete.', $payload['certificationStatement']);
        $this->assertSame('Servizo Logistics', $payload['vendor']['name']);
        $this->assertSame('PO-TRIP-001', $payload['linkedPo']['poNumber']);
        $this->assertSame('NGN', $payload['currency']);
        $this->assertSame(107500.0, $payload['totalAmount']);
        $this->assertNull($payload['issuedBy']);
    }
}
