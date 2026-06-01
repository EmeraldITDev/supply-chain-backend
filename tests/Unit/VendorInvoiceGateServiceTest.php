<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Services\FinanceAp\VendorInvoiceGateService;
use App\Services\PaymentScheduleService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Tests\TestCase;

class VendorInvoiceGateServiceTest extends TestCase
{
    public function test_legacy_mrf_cannot_submit_invoice(): void
    {
        config(['finance_ap.cutover_date' => '2026-06-01']);

        $mrf = new MRF([
            'created_at' => Carbon::parse('2026-05-01'),
            'selected_vendor_id' => 1,
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
        ]);

        $status = app(VendorInvoiceGateService::class)->status($mrf);

        $this->assertFalse($status['canSubmit']);
        $this->assertFalse($status['usesFinanceAp']);
    }

    public function test_advance_schedule_opens_gate_after_scd_quote_approval(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => WorkflowStateService::STATE_INVOICE_APPROVED,
        ]);

        $this->bindScheduleService(
            $this->scheduleWithMilestones([
                $this->milestone(PaymentMilestone::TRIGGER_ON_ADVANCE),
            ]),
            advance: true
        );

        $status = app(VendorInvoiceGateService::class)->status($mrf);

        $this->assertTrue($status['canSubmit']);
        $this->assertSame('advance', $status['gateType']);
    }

    public function test_advance_schedule_blocks_gate_before_scd_quote_approval(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => WorkflowStateService::STATE_VENDOR_SELECTED,
        ]);

        $this->bindScheduleService(
            $this->scheduleWithMilestones([
                $this->milestone(PaymentMilestone::TRIGGER_ON_ADVANCE),
            ]),
            advance: true
        );

        $status = app(VendorInvoiceGateService::class)->status($mrf);

        $this->assertFalse($status['canSubmit']);
        $this->assertSame('advance', $status['gateType']);
    }

    public function test_delivery_schedule_opens_gate_after_grn_confirmed(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => WorkflowStateService::STATE_DELIVERY_CONFIRMATION_COMPLETE,
            'grn_completed' => true,
            'grn_url' => 'https://example.test/grn.pdf',
        ]);

        $this->bindScheduleService(
            $this->scheduleWithMilestones([
                $this->milestone(PaymentMilestone::TRIGGER_UPON_DELIVERY, ['grn', 'waybill']),
            ]),
            advance: false
        );

        $status = app(VendorInvoiceGateService::class)->status($mrf);

        $this->assertTrue($status['canSubmit']);
        $this->assertSame('delivery', $status['gateType']);
    }

    public function test_delivery_schedule_blocks_gate_before_grn_confirmed(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
        ]);

        $this->bindScheduleService(
            $this->scheduleWithMilestones([
                $this->milestone(PaymentMilestone::TRIGGER_UPON_DELIVERY, ['grn', 'waybill']),
            ]),
            advance: false
        );

        $status = app(VendorInvoiceGateService::class)->status($mrf);

        $this->assertFalse($status['canSubmit']);
        $this->assertSame('delivery', $status['gateType']);
    }

    private function financeApMrf(array $attrs = []): MRF
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        return new MRF(array_merge([
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 1,
        ], $attrs));
    }

    /**
     * @param  list<PaymentMilestone>  $milestones
     */
    private function scheduleWithMilestones(array $milestones): PaymentSchedule
    {
        $schedule = new PaymentSchedule();
        $schedule->setRelation('milestones', collect($milestones));

        return $schedule;
    }

    /**
     * @param  list<string>  $requiredDocuments
     */
    private function milestone(string $trigger, array $requiredDocuments = ['signed_po', 'pfi']): PaymentMilestone
    {
        return new PaymentMilestone([
            'milestone_number' => 1,
            'label' => 'Test milestone',
            'trigger_condition' => $trigger,
            'required_documents' => $requiredDocuments,
            'status' => PaymentMilestone::STATUS_PENDING,
        ]);
    }

    private function bindScheduleService(PaymentSchedule $schedule, bool $advance): void
    {
        $this->mock(PaymentScheduleService::class, function ($mock) use ($schedule, $advance) {
            $mock->shouldReceive('findForMrf')->andReturn($schedule);
            $mock->shouldReceive('hasAdvanceMilestone')->andReturn($advance);
        });
    }
}
