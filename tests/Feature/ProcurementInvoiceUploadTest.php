<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\ProcurementDocument;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinanceAp\VendorInvoiceGateService;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcurementInvoiceUploadTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private MRF $mrf;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.documents_disk' => 's3']);
        Storage::fake('s3');

        $this->vendor = Vendor::create([
            'vendor_id' => 'V-INV-001',
            'name' => 'Acme Supplies',
            'email' => 'acme@example.com',
            'status' => 'Active',
            'category' => 'General',
        ]);

        $this->mrf = MRF::create([
            'mrf_id' => 'MRF-INV-'.uniqid(),
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
            'status' => 'finance',
            'workflow_state' => WorkflowStateService::STATE_FINANCE_IN_REVIEW,
            'current_stage' => 'finance',
            'estimated_cost' => 50000,
            'scm_transaction_id' => (string) Str::uuid(),
            'selected_vendor_id' => $this->vendor->id,
        ]);
        // The model does not keep po_number on create; issued POs have one, which opens procurement uploads.
        \Illuminate\Support\Facades\DB::table($this->mrf->getTable())->where('id', $this->mrf->id)->update(['po_number' => 'PO-INV-0001']);
        $this->mrf->refresh();

        Sanctum::actingAs(User::factory()->create(['supply_chain_role' => 'procurement_manager']));
    }

    private function gate(bool $open): void
    {
        $this->mock(VendorInvoiceGateService::class, function (MockInterface $mock) use ($open) {
            $mock->shouldReceive('canSubmitInvoice')->andReturn($open);
            $mock->shouldReceive('status')->andReturn([
                'canSubmit' => $open,
                'reason' => $open ? 'Open' : 'Waiting for delivery confirmation and GRN before vendor invoice submission.',
                'gateType' => 'delivery',
                'usesFinanceAp' => true,
            ]);
        });
    }

    private function uploadInvoice()
    {
        return $this->post("/api/mrfs/{$this->mrf->mrf_id}/procurement-documents", [
            'type' => ProcurementDocument::TYPE_VENDOR_INVOICE,
            'file' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    public function test_procurement_can_upload_an_invoice_once_the_vendor_invoice_gate_is_open(): void
    {
        $this->gate(true);

        $this->uploadInvoice()
            ->assertSuccessful()
            ->assertJsonPath('data.documents.0.type', ProcurementDocument::TYPE_VENDOR_INVOICE);

        $document = ProcurementDocument::where('mrf_id', $this->mrf->id)->firstOrFail();
        $this->assertSame(ProcurementDocument::TYPE_VENDOR_INVOICE, $document->type);
        $this->assertSame($this->vendor->id, (int) $document->vendor_id, 'Stored against the selected vendor, as a portal submission is');
        $this->assertNotNull($this->mrf->fresh()->invoice_url);
    }

    public function test_invoice_upload_is_refused_while_the_gate_is_closed(): void
    {
        $this->gate(false);

        $this->uploadInvoice()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.failed.0.error', 'Waiting for delivery confirmation and GRN before vendor invoice submission.');

        $this->assertSame(0, ProcurementDocument::where('mrf_id', $this->mrf->id)->count());
    }

    public function test_a_second_invoice_is_refused_while_one_is_active(): void
    {
        $this->gate(true);
        $this->uploadInvoice()->assertSuccessful();

        $this->uploadInvoice()
            ->assertJsonPath('data.failed.0.error', 'An active vendor invoice already exists for this MRF.');

        $this->assertSame(1, ProcurementDocument::where('mrf_id', $this->mrf->id)->count());
    }
}
