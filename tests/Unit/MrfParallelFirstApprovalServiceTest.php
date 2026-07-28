<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Services\MrfParallelFirstApprovalService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MrfParallelFirstApprovalServiceTest extends TestCase
{
    #[Test]
    public function it_allows_parallel_first_approval_until_the_first_approver_wins(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $this->assertTrue($service->canConsumeFirstApproval($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR));
        $this->assertTrue($service->canConsumeFirstApproval($mrf, MrfParallelFirstApprovalService::ROLE_EXECUTIVE));
    }

    #[Test]
    public function it_blocks_a_second_parallel_approval_after_the_first_wins(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => MrfParallelFirstApprovalService::ROLE_EXECUTIVE,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $this->assertFalse($service->canConsumeFirstApproval($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR));
    }

    #[Test]
    public function it_maps_the_first_winning_role_to_the_procurement_handoff_state(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $result = $service->resolveApprovalTransition($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, true);

        $this->assertSame('procurement_review', $result['status']);
        $this->assertSame('procurement', $result['current_stage']);
        $this->assertSame('procurement_review', $result['workflow_state']);
        $this->assertSame(MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, $result['first_approval_by_role']);
    }
}
