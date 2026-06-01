<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\PaymentMilestone;
use App\Models\PaymentSchedule;
use App\Services\FinanceAp\ClosureReadinessService;
use App\Services\PaymentScheduleService;
use App\Services\ProcurementDocumentService;
use Carbon\Carbon;
use Tests\TestCase;

class ClosureReadinessServiceTest extends TestCase
{
    public function test_can_close_when_all_milestones_paid_and_documents_present(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->paidScheduleFromTemplate('70_30_delivery');

        $this->bindServices($schedule, missingDocuments: []);

        $readiness = app(ClosureReadinessService::class)->evaluate($mrf);

        $this->assertTrue($readiness['financially_complete']);
        $this->assertTrue($readiness['operationally_complete']);
        $this->assertTrue($readiness['can_close']);
        $this->assertSame([], $readiness['blockers']);
    }

    public function test_blocks_close_when_milestone_unpaid(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->bindServices($schedule, missingDocuments: []);

        $readiness = app(ClosureReadinessService::class)->evaluate($mrf);

        $this->assertFalse($readiness['financially_complete']);
        $this->assertFalse($readiness['can_close']);
        $this->assertNotEmpty($readiness['blockers']);
    }

    public function test_blocks_close_when_required_documents_missing(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->paidScheduleFromTemplate('70_30_delivery');

        $this->bindServices($schedule, missingDocuments: ['grn']);

        $readiness = app(ClosureReadinessService::class)->evaluate($mrf);

        $this->assertTrue($readiness['financially_complete']);
        $this->assertFalse($readiness['operationally_complete']);
        $this->assertFalse($readiness['can_close']);
        $this->assertContains('Required milestone documents are still missing.', $readiness['blockers']);
    }

    public function test_service_completion_template_requires_jcc(): void
    {
        $mrf = $this->financeApMrf();
        $schedule = $this->paidScheduleFromTemplate('50_50_completion');

        $this->bindServices($schedule, missingDocuments: ['jcc']);

        $readiness = app(ClosureReadinessService::class)->evaluate($mrf);

        $this->assertFalse($readiness['operationally_complete']);
        $this->assertFalse($readiness['can_close']);
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

    private function paidScheduleFromTemplate(string $templateKey): PaymentSchedule
    {
        $schedule = $this->scheduleFromTemplate($templateKey);
        $schedule->setRelation('milestones', $schedule->milestones->map(function (PaymentMilestone $milestone) {
            $milestone->status = PaymentMilestone::STATUS_PAID;

            return $milestone;
        }));

        return $schedule;
    }

    /**
     * @param  list<string>  $missingDocuments
     */
    private function bindServices(PaymentSchedule $schedule, array $missingDocuments): void
    {
        $real = app(PaymentScheduleService::class);

        $this->mock(PaymentScheduleService::class, function ($mock) use ($schedule, $real) {
            $mock->shouldReceive('findForMrf')->andReturn($schedule);
            $mock->shouldReceive('requiredDocumentsForMilestone')
                ->andReturnUsing(fn (PaymentMilestone $m) => $real->requiredDocumentsForMilestone($m));
            $mock->shouldReceive('allMilestonesFinanciallyComplete')
                ->andReturnUsing(fn (?PaymentSchedule $s) => $real->allMilestonesFinanciallyComplete($s ?? $schedule));
        });

        $this->mock(ProcurementDocumentService::class, function ($mock) use ($missingDocuments, $schedule, $real) {
            $mock->shouldReceive('resolveVendorId')->andReturn(1);
            $mock->shouldReceive('missingDocumentTypes')
                ->andReturnUsing(function ($mrf, array $types) use ($missingDocuments, $real) {
                    return array_values(array_intersect($types, $missingDocuments));
                });
        });
    }
}
