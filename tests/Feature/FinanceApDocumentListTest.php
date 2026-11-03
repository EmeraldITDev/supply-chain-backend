<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\FinancePackageBuilder;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceApDocumentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'finance_ap.integration_inbound_key' => 'fap-key',
            'filesystems.documents_disk' => 's3',
        ]);
        Storage::fake('s3');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mrf(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => 'MRF-FAP-'.uniqid(),
            'title' => 'Office chairs',
            'category' => 'goods',
            'urgency' => 'Medium',
            'description' => 'Office chairs',
            'quantity' => '1',
            'justification' => 'Test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Operations',
            'date' => now(),
            'status' => 'completed',
            'workflow_state' => WorkflowStateService::STATE_CLOSED,
            'current_stage' => 'completed',
            'estimated_cost' => 50000,
            'scm_transaction_id' => (string) Str::uuid(),
            'selected_vendor_id' => Vendor::create([
                'vendor_id' => 'V-FAP-'.uniqid(),
                'name' => 'Acme Supplies',
                'email' => uniqid().'@example.com',
                'status' => 'Active',
                'category' => 'General',
            ])->id,
            'po_number' => 'PO-TEST-'.uniqid(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function document(MRF $mrf, string $type, array $overrides = []): ProcurementDocument
    {
        $path = "procurement-documents/2026/07/{$mrf->mrf_id}/{$type}.pdf";
        Storage::disk('s3')->put($path, 'pdf-bytes');

        return ProcurementDocument::create(array_merge([
            'mrf_id' => $mrf->id,
            'type' => $type,
            'file_name' => "{$type}.pdf",
            'file_path' => $path,
            'file_url' => 'https://expired.example/request-has-expired',
            'uploaded_at' => now(),
            'version' => 1,
            'is_active' => true,
        ], $overrides));
    }

    public function test_lists_active_documents_for_a_closed_mrf(): void
    {
        $mrf = $this->mrf();
        $pfi = $this->document($mrf, ProcurementDocument::TYPE_PFI);
        $po = $this->document($mrf, ProcurementDocument::TYPE_SIGNED_PO);
        $this->document($mrf, ProcurementDocument::TYPE_PFI, ['is_active' => false, 'file_name' => 'old_pfi.pdf']);

        $documents = $this->withHeaders(['X-Api-Key' => 'fap-key'])
            ->getJson("/api/v1/integrations/scm/documents/{$mrf->scm_transaction_id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.documents')
            ->json('data.documents');

        $byId = collect($documents)->keyBy('documentId');
        $this->assertSame('pfi', $byId[(string) $pfi->id]['type']);
        $this->assertSame('purchase_order', $byId[(string) $po->id]['type']);
        $this->assertSame('signed_po', $byId[(string) $po->id]['scmDocumentType']);
        $this->assertArrayNotHasKey('fileUrl', $byId[(string) $pfi->id], 'Links are fetched per document when used');
    }

    public function test_document_list_requires_the_integration_key(): void
    {
        $mrf = $this->mrf();

        $this->getJson("/api/v1/integrations/scm/documents/{$mrf->scm_transaction_id}")->assertUnauthorized();
        $this->withHeaders(['X-Api-Key' => 'fap-key'])
            ->getJson('/api/v1/integrations/scm/documents/unknown-transaction')
            ->assertNotFound();
    }

    public function test_package_manifest_signs_fresh_links_instead_of_stored_ones(): void
    {
        $mrf = $this->mrf();
        $this->document($mrf, ProcurementDocument::TYPE_PFI);

        $manifest = (new \ReflectionMethod(FinancePackageBuilder::class, 'buildDocumentManifest'))
            ->invoke(app(FinancePackageBuilder::class), $mrf);

        $this->assertCount(1, $manifest);
        $this->assertNotSame('https://expired.example/request-has-expired', $manifest[0]['fileUrl']);
        $this->assertNotEmpty($manifest[0]['fileUrl']);
    }

    public function test_resend_command_previews_stuck_mrfs_without_sending(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);
        Http::fake();

        $stuck = $this->mrf(['workflow_state' => WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING, 'status' => 'finance']);
        $this->mrf(['workflow_state' => WorkflowStateService::STATE_FINANCE_HANDOFF_PENDING, 'finance_ap_case_id' => 'FA-2026-00001']);
        $this->mrf();

        $this->artisan('finance-ap:resend-stuck-packages')
            ->expectsOutputToContain($stuck->mrf_id)
            ->expectsOutputToContain('Preview only: 1 MRF(s) would be re-sent')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'integrations/scm/packages'));
        $this->assertNull($stuck->fresh()->finance_ap_case_id);
    }
}
