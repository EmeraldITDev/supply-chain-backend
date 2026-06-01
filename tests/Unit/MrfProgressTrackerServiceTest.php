<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Models\ProcurementDocument;
use App\Services\FinanceAp\DeliveryConfirmationService;
use App\Services\FinanceAp\MrfProgressTrackerService;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use App\Services\WorkflowStateService;
use Carbon\Carbon;
use Tests\TestCase;

class MrfProgressTrackerServiceTest extends TestCase
{
    public function test_hundred_percent_advance_hides_delivery_phase(): void
    {
        $mrf = $this->financeApMrf(['workflow_state' => WorkflowStateService::STATE_PO_SIGNED]);
        $schedule = $this->scheduleFromTemplate('100_advance');

        $this->mockDependencies($schedule, [
            'activeByType' => [],
        ]);

        $payload = app(MrfProgressTrackerService::class)->build($mrf);

        $this->assertTrue($payload['meta']['hideDeliveryPhase']);
        $phaseIds = collect($payload['phases'])->pluck('id')->all();
        $this->assertNotContains('delivery', $phaseIds);
        $this->assertContains('payment', $phaseIds);

        $financeStep = collect($payload['steps'])->firstWhere('key', 'finance_review');
        $this->assertSame(10, $financeStep['step']);
    }

    public function test_delivery_phase_present_for_split_schedule(): void
    {
        $mrf = $this->financeApMrf(['workflow_state' => WorkflowStateService::STATE_PO_SIGNED]);
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->mockDependencies($schedule, [
            'activeByType' => [],
        ]);

        $payload = app(MrfProgressTrackerService::class)->build($mrf);

        $this->assertFalse($payload['meta']['hideDeliveryPhase']);
        $this->assertContains('delivery', collect($payload['phases'])->pluck('id')->all());

        $financeStep = collect($payload['steps'])->firstWhere('key', 'finance_review');
        $this->assertSame(12, $financeStep['step']);
    }

    public function test_vendor_invoice_step_complete_when_active_document_present(): void
    {
        $mrf = $this->financeApMrf(['workflow_state' => WorkflowStateService::STATE_PO_SIGNED]);
        $schedule = $this->scheduleFromTemplate('100_advance');

        $this->mockDependencies($schedule, [
            'activeByType' => [
                ProcurementDocument::TYPE_VENDOR_INVOICE => [
                    'type' => ProcurementDocument::TYPE_VENDOR_INVOICE,
                    'uploadedAt' => '2026-06-10T12:00:00+00:00',
                    'isActive' => true,
                ],
            ],
        ]);

        $payload = app(MrfProgressTrackerService::class)->build($mrf);
        $invoiceStep = collect($payload['steps'])->firstWhere('key', 'vendor_final_invoice');

        $this->assertSame('completed', $invoiceStep['status']);
        $this->assertTrue($invoiceStep['documentSatisfied']);
        $this->assertSame('2026-06-10T12:00:00+00:00', $payload['stageTimestamps']['vendor_invoice_submitted_at']);
    }

    public function test_quotations_timestamp_query_qualifies_joined_columns(): void
    {
        $mrf = new MRF(['id' => 175]);

        $sql = $mrf->quotations()
            ->whereIn('quotations.status', ['submitted', 'approved', 'selected'])
            ->orderBy('quotations.created_at')
            ->toSql();

        $this->assertStringContainsString('quotations.created_at', $sql);
        $this->assertStringContainsString('quotations.status', $sql);
        $this->assertDoesNotMatchRegularExpression('/order by ["`]?created_at["`]?\s/i', $sql);
    }

    public function test_payment_phase_includes_milestone_rows(): void
    {
        $mrf = $this->financeApMrf([
            'workflow_state' => WorkflowStateService::STATE_MILESTONE_PAYMENT_IN_PROGRESS,
            'currency' => 'NGN',
        ]);
        $schedule = $this->scheduleFromTemplate('70_30_delivery');
        $schedule->milestones->first()->status = PaymentMilestone::STATUS_PAID;
        $schedule->milestones->first()->paid_at = Carbon::parse('2026-07-01');

        $this->mockDependencies($schedule, ['activeByType' => []]);

        $payload = app(MrfProgressTrackerService::class)->build($mrf);
        $milestoneSteps = collect($payload['steps'])->filter(fn (array $s) => str_starts_with($s['key'], 'milestone_'));

        $this->assertCount(2, $milestoneSteps);
        $this->assertSame('completed', $milestoneSteps->first()['status']);
        $this->assertArrayHasKey('amount', $milestoneSteps->first());
        $this->assertArrayHasKey('percentage', $milestoneSteps->first());
    }

    private function financeApMrf(array $attrs = []): MRF
    {
        config(['finance_ap.cutover_date' => '2026-01-01']);

        return new MRF(array_merge([
            'mrf_id' => 'MRF-TEST-001',
            'formatted_id' => 'MRF-2026-001',
            'title' => 'Test MRF',
            'created_at' => Carbon::parse('2026-06-01'),
            'selected_vendor_id' => 1,
        ], $attrs));
    }

    private function scheduleFromTemplate(string $templateKey): PaymentSchedule
    {
        $template = config("payment_term_templates.{$templateKey}");
        $milestones = collect($template['milestones'])->map(fn (array $row) => new PaymentMilestone([
            'id' => $row['milestone_number'],
            'milestone_number' => $row['milestone_number'],
            'label' => $row['label'],
            'percentage' => $row['percentage'],
            'amount' => 100000 * ((float) $row['percentage'] / 100),
            'trigger_condition' => $row['trigger_condition'],
            'required_documents' => $row['required_documents'],
            'status' => PaymentMilestone::STATUS_PENDING,
        ]));

        $schedule = new PaymentSchedule(['template_key' => $templateKey, 'mrf_id' => 1]);
        $schedule->setRelation('milestones', $milestones);

        return $schedule;
    }

    /**
     * @param  array<string, mixed>  $documentPayload
     */
    private function mockDependencies(PaymentSchedule $schedule, array $documentPayload): void
    {
        $realPayment = app(PaymentScheduleService::class);

        $this->mock(PaymentScheduleService::class, function ($mock) use ($schedule, $realPayment) {
            $mock->shouldReceive('findForMrf')->andReturn($schedule);
            $mock->shouldReceive('toApiArray')->andReturnUsing(fn (PaymentSchedule $s) => [
                'id' => 1,
                'templateKey' => $s->template_key,
                'milestones' => $s->milestones->map(fn (PaymentMilestone $m) => [
                    'id' => $m->id,
                    'milestoneNumber' => $m->milestone_number,
                    'label' => $m->label,
                    'percentage' => (float) $m->percentage,
                    'triggerCondition' => $m->trigger_condition,
                    'status' => $m->status,
                ])->values()->all(),
            ]);
            $mock->shouldReceive('requiresDeliveryConfirmationStage')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $realPayment->requiresDeliveryConfirmationStage($s ?? $schedule));
            $mock->shouldReceive('hasAdvanceMilestone')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $realPayment->hasAdvanceMilestone($s ?? $schedule));
        });

        $activeByType = $documentPayload['activeByType'] ?? [];
        $documentsByType = [];
        foreach ($activeByType as $type => $doc) {
            $documentsByType[$type] = [$doc];
        }

        $this->mock(ProcurementDocumentService::class, function ($mock) use ($activeByType, $documentsByType) {
            $mock->shouldReceive('listGroupedForMrf')->andReturn([
                'documents' => collect($activeByType)->values()->filter()->all(),
                'documentsByType' => $documentsByType,
                'activeByType' => $activeByType,
            ]);
        });

        $this->mock(DeliveryConfirmationService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn([
                'required' => true,
                'satisfied' => false,
                'missingDocuments' => ['grn', 'waybill'],
                'requiredDocuments' => ['grn', 'waybill'],
            ]);
        });
    }
}
