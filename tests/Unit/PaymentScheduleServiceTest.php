<?php

namespace Tests\Unit;

use App\Services\PaymentScheduleService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentScheduleServiceTest extends TestCase
{
    public function test_rejects_milestones_not_totaling_one_hundred_percent(): void
    {
        $service = app(PaymentScheduleService::class);

        $this->expectException(ValidationException::class);

        $service->validateMilestonePercentages([
            ['percentage' => 60],
            ['percentage' => 30],
        ]);
    }

    public function test_accepts_milestones_totaling_one_hundred_percent(): void
    {
        $service = app(PaymentScheduleService::class);

        $service->validateMilestonePercentages([
            ['percentage' => 70],
            ['percentage' => 30],
        ]);

        $this->assertTrue(true);
    }

    public function test_list_templates_includes_four_seed_templates(): void
    {
        $templates = app(PaymentScheduleService::class)->listTemplates();

        $this->assertCount(4, $templates);

        $keys = array_column($templates, 'key');

        $this->assertContains('100_advance', $keys);
        $this->assertContains('70_30_delivery', $keys);
        $this->assertContains('50_50_completion', $keys);
        $this->assertContains('30_40_30_mixed', $keys);
    }

    public function test_trigger_condition_labels(): void
    {
        $service = app(PaymentScheduleService::class);

        $this->assertSame('Advance', $service->triggerConditionLabel('on_advance'));
        $this->assertSame('Upon Delivery', $service->triggerConditionLabel('upon_delivery'));
        $this->assertSame('Upon Completion', $service->triggerConditionLabel('upon_completion'));
    }

    public function test_advance_only_schedule_skips_delivery_confirmation_stage(): void
    {
        $service = app(PaymentScheduleService::class);
        $schedule = $this->scheduleFromTemplate('100_advance');

        $this->assertTrue($service->hasAdvanceMilestone($schedule));
        $this->assertTrue($service->isAdvanceOnlySchedule($schedule));
        $this->assertFalse($service->requiresDeliveryConfirmationStage($schedule));
    }

    public function test_delivery_template_requires_delivery_confirmation_stage(): void
    {
        $service = app(PaymentScheduleService::class);
        $schedule = $this->scheduleFromTemplate('70_30_delivery');

        $this->assertTrue($service->requiresDeliveryConfirmationStage($schedule));
        $this->assertFalse($service->isAdvanceOnlySchedule($schedule));
    }

    public function test_completion_template_requires_jcc_not_delivery_stage_by_trigger_only(): void
    {
        $service = app(PaymentScheduleService::class);
        $schedule = $this->scheduleFromTemplate('50_50_completion');

        $this->assertFalse($service->requiresDeliveryConfirmationStage($schedule));

        $completionMilestone = $schedule->milestones->last();
        $this->assertSame(['jcc'], $service->requiredDocumentsForMilestone($completionMilestone));
    }

    private function scheduleFromTemplate(string $templateKey): \App\Models\PaymentSchedule
    {
        $template = config("payment_term_templates.{$templateKey}");
        $milestones = collect($template['milestones'])->map(fn (array $row) => new \App\Models\PaymentMilestone([
            'milestone_number' => $row['milestone_number'],
            'label' => $row['label'],
            'percentage' => $row['percentage'],
            'trigger_condition' => $row['trigger_condition'],
            'required_documents' => $row['required_documents'],
            'status' => \App\Models\PaymentMilestone::STATUS_PENDING,
        ]));

        $schedule = new \App\Models\PaymentSchedule(['template_key' => $templateKey]);
        $schedule->setRelation('milestones', $milestones);

        return $schedule;
    }
}
