<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Services\FinanceAp\DeliveryConfirmationService;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use Carbon\Carbon;
use Tests\TestCase;

class DeliveryConfirmationServiceTest extends TestCase
{
    public function test_advance_only_schedule_does_not_require_delivery_stage(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->scheduleFromTemplate('100_advance');

        $this->bindPaymentScheduleService($schedule);
        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')->andReturn([]);
        });

        $evaluation = app(DeliveryConfirmationService::class)->evaluate($mrf);

        $this->assertFalse($evaluation['required']);
        $this->assertTrue($evaluation['satisfied']);
        $this->assertFalse(app(DeliveryConfirmationService::class)->requiresStage($mrf));
    }

    public function test_delivery_schedule_reports_missing_documents(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->bindPaymentScheduleService($schedule);
        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')->andReturn(['grn', 'waybill']);
        });

        $evaluation = app(DeliveryConfirmationService::class)->evaluate($mrf);

        $this->assertTrue($evaluation['required']);
        $this->assertFalse($evaluation['satisfied']);
        $this->assertSame(['grn', 'waybill'], $evaluation['missingDocuments']);
    }

    public function test_delivery_schedule_is_satisfied_when_documents_present(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->bindPaymentScheduleService($schedule);
        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')->andReturn([]);
            $mock->shouldReceive('findActiveDocument')->andReturn(null);
            $mock->shouldReceive('transform')->andReturn([]);
        });

        $evaluation = app(DeliveryConfirmationService::class)->evaluate($mrf);

        $this->assertTrue($evaluation['required']);
        $this->assertTrue($evaluation['satisfied']);
        $this->assertSame(['grn', 'waybill'], $evaluation['requiredDocuments']);
    }

    public function test_panel_payload_includes_checklist_for_current_milestone(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => \App\Services\WorkflowStateService::STATE_DELIVERY_CONFIRMATION_PENDING,
        ]);
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->bindPaymentScheduleService($schedule);

        $grnDocument = new \App\Models\ProcurementDocument([
            'id' => 10,
            'type' => \App\Models\ProcurementDocument::TYPE_GRN,
            'file_name' => 'grn.pdf',
            'file_url' => 'https://example.test/grn.pdf',
            'is_active' => true,
        ]);

        $this->mock(ProcurementDocumentService::class, function ($mock) use ($grnDocument) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')->andReturn(['waybill']);
            $mock->shouldReceive('findActiveDocument')->andReturnUsing(function ($mrf, $type) use ($grnDocument) {
                return $type === \App\Models\ProcurementDocument::TYPE_GRN ? $grnDocument : null;
            });
            $mock->shouldReceive('transform')->andReturn([
                'id' => 10,
                'type' => 'grn',
                'fileName' => 'grn.pdf',
                'fileUrl' => 'https://example.test/grn.pdf',
            ]);
        });

        $panel = app(DeliveryConfirmationService::class)->panelPayload($mrf);

        $this->assertTrue($panel['showPanel']);
        $this->assertCount(2, $panel['checklist']);
        $this->assertSame('grn', $panel['checklist'][0]['type']);
        $this->assertTrue($panel['checklist'][0]['satisfied']);
        $this->assertFalse($panel['checklist'][1]['satisfied']);
        $this->assertSame(['upload_waybill'], $panel['checklist'][1]['actions']);
    }

    public function test_advance_only_schedule_hides_panel(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => \App\Services\WorkflowStateService::STATE_PO_SIGNED,
        ]);
        $schedule = $this->scheduleFromTemplate('100_advance');

        $this->bindPaymentScheduleService($schedule);
        $this->mock(ProcurementDocumentService::class, function ($mock) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')->andReturn([]);
        });

        $panel = app(DeliveryConfirmationService::class)->panelPayload($mrf);

        $this->assertFalse($panel['required']);
        $this->assertFalse($panel['showPanel']);
    }

    private function financeApMrf(array $attrs = []): MRF
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        return new MRF(array_merge([
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 1,
        ], $attrs));
    }

    private function scheduleFromTemplate(string $templateKey): PaymentSchedule
    {
        $template = config("payment_term_templates.{$templateKey}");
        $milestones = collect($template['milestones'])->map(fn (array $row) => new PaymentMilestone([
            'milestone_number' => $row['milestone_number'],
            'label' => $row['label'],
            'percentage' => $row['percentage'],
            'trigger_condition' => $row['trigger_condition'],
            'required_documents' => $row['required_documents'],
            'status' => PaymentMilestone::STATUS_PENDING,
        ]));

        $schedule = new PaymentSchedule(['template_key' => $templateKey]);
        $schedule->setRelation('milestones', $milestones);

        return $schedule;
    }

    private function bindPaymentScheduleService(PaymentSchedule $schedule): void
    {
        $real = app(PaymentScheduleService::class);

        $this->mock(PaymentScheduleService::class, function ($mock) use ($schedule, $real) {
            $mock->shouldReceive('findForMrf')->andReturn($schedule);
            $mock->shouldReceive('requiresDeliveryConfirmationStage')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $real->requiresDeliveryConfirmationStage($s ?? $schedule));
            $mock->shouldReceive('milestonesWithOperationalDocumentRequirements')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $real->milestonesWithOperationalDocumentRequirements($s ?? $schedule));
            $mock->shouldReceive('milestoneRequiresDeliveryConfirmation')
                ->andReturnUsing(fn (PaymentMilestone $m) => $real->milestoneRequiresDeliveryConfirmation($m));
            $mock->shouldReceive('requiredDocumentsForMilestone')
                ->andReturnUsing(fn (PaymentMilestone $m) => $real->requiredDocumentsForMilestone($m));
            $mock->shouldReceive('currentPendingMilestone')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $real->currentPendingMilestone($s ?? $schedule));
        });
    }
}
