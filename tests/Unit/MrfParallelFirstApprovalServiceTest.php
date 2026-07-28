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
    public function it_allows_the_other_parallel_approver_to_complete_the_second_signoff(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => MrfParallelFirstApprovalService::ROLE_EXECUTIVE,
            'executive_approved' => true,
            'director_approved_at' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $this->assertTrue($service->canConsumeFirstApproval($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR));
    }

    #[Test]
    public function it_keeps_parallel_first_approval_pending_until_both_roles_have_approved(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => null,
            'executive_approved' => false,
            'director_approved_at' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $result = $service->resolveApprovalTransition($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, true);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(MrfParallelFirstApprovalService::STATE, $result['current_stage']);
        $this->assertSame(MrfParallelFirstApprovalService::STATE, $result['workflow_state']);
        $this->assertSame(MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, $result['first_approval_by_role']);
    }

    #[Test]
    public function it_allows_the_other_parallel_approver_after_one_role_has_approved(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => MrfParallelFirstApprovalService::ROLE_EXECUTIVE,
            'executive_approved' => true,
            'director_approved_at' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();

        $this->assertTrue($service->canConsumeFirstApproval($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR));
    }

    #[Test]
    public function it_transitions_to_procurement_only_after_both_parallel_approvals_are_complete(): void
    {
        $mrf = new MRF([
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'first_approval_by_role' => MrfParallelFirstApprovalService::ROLE_EXECUTIVE,
            'executive_approved' => true,
            'director_approved_at' => now(),
        ]);

        $service = new MrfParallelFirstApprovalService();

        $result = $service->resolveApprovalTransition($mrf, MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, true);

        $this->assertSame('procurement_review', $result['status']);
        $this->assertSame('procurement', $result['current_stage']);
        $this->assertSame('procurement_review', $result['workflow_state']);
    }
}
