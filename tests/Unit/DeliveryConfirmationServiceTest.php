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
        });

        $evaluation = app(DeliveryConfirmationService::class)->evaluate($mrf);

        $this->assertTrue($evaluation['required']);
        $this->assertTrue($evaluation['satisfied']);
        $this->assertSame(['grn', 'waybill'], $evaluation['requiredDocuments']);
    }

    private function financeApMrf(): MRF
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        return new MRF([
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 1,
        ]);
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
