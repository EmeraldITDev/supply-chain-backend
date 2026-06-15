<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\User;
use App\Models\Vendor;
use App\Services\FinanceAp\VendorInvoiceGateService;
use App\Services\FinanceAp\VendorInvoiceSubmissionService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class VendorInvoiceSubmissionServiceTest extends TestCase
{
    public function test_rejects_submission_when_gate_is_closed(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        $mrf = new MRF([
            'id' => 1,
            'mrf_id' => 'MRF-TEST-001',
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 5,
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
        ]);

        $vendor = new Vendor(['id' => 5, 'vendor_id' => 'VEND-001']);

        $this->mock(VendorInvoiceGateService::class, function ($mock) {
            $mock->shouldReceive('canSubmitInvoice')->andReturn(false);
            $mock->shouldReceive('status')->andReturn([
                'canSubmit' => false,
                'reason' => 'Waiting for delivery confirmation and GRN before vendor invoice submission.',
                'gateType' => 'delivery',
                'usesFinanceAp' => true,
            ]);
        });

        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('hasActiveDocument')->andReturn(false);
        });

        $service = app(VendorInvoiceSubmissionService::class);
        $user = new User(['id' => 10, 'supply_chain_role' => 'vendor']);
        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Waiting for delivery confirmation');

        $service->submit($mrf, $vendor, $user, $file);
    }

    public function test_rejects_second_submission(): void
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        $mrf = new MRF([
            'id' => 1,
            'mrf_id' => 'MRF-TEST-002',
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 5,
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
        ]);

        $vendor = new Vendor(['id' => 5, 'vendor_id' => 'VEND-001']);

        $this->mock(VendorInvoiceGateService::class, function ($mock) {
            $mock->shouldReceive('canSubmitInvoice')->never();
        });

        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('hasActiveDocument')->andReturn(true);
        });

        $service = app(VendorInvoiceSubmissionService::class);
        $user = new User(['id' => 10, 'supply_chain_role' => 'vendor']);
        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $service->submit($mrf, $vendor, $user, $file);
    }

    public function test_rejects_vendor_that_is_not_selected(): void
    {
        $mrf = new MRF([
            'mrf_id' => 'MRF-TEST-003',
            'selected_vendor_id' => 5,
        ]);
        $vendor = new Vendor(['id' => 99]);

        $service = app(VendorInvoiceSubmissionService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not the selected vendor');

        $service->statusForVendor($mrf, $vendor);
    }
}
