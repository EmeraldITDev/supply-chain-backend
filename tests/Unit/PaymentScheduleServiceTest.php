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
}
