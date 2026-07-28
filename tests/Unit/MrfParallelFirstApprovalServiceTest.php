<?php

namespace Tests\Unit;

use App\Models\MRF;
use App\Models\User;
use App\Services\MrfParallelFirstApprovalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    #[Test]
    public function it_persists_a_partial_parallel_approval_to_the_parent_mrf_and_history_table(): void
    {
        Schema::dropIfExists('mrf_approval_history');
        Schema::dropIfExists('m_r_f_s');
        Schema::dropIfExists('users');

        Schema::create('users', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('m_r_f_s', function ($table): void {
            $table->id();
            $table->string('mrf_id')->nullable();
            $table->uuid('scm_transaction_id')->nullable();
            $table->string('title')->nullable();
            $table->string('workflow_state')->nullable();
            $table->string('current_stage')->nullable();
            $table->string('status')->nullable();
            $table->string('first_approval_by_role')->nullable();
            $table->timestamp('director_approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mrf_approval_history', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('mrf_id');
            $table->string('action');
            $table->string('stage');
            $table->unsignedBigInteger('performed_by');
            $table->string('performer_name');
            $table->string('performer_role');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        $user = User::create([
            'name' => 'Viva Musa',
            'email' => 'viva@example.com',
        ]);

        $mrf = MRF::create([
            'mrf_id' => 'MRF-TEST-001',
            'title' => 'Test MRF',
            'workflow_state' => MrfParallelFirstApprovalService::STATE,
            'current_stage' => MrfParallelFirstApprovalService::STATE,
            'status' => 'pending',
            'first_approval_by_role' => null,
        ]);

        $service = new MrfParallelFirstApprovalService();
        $service->persistPartialApproval(
            $mrf,
            MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR,
            [
                'first_approval_by_role' => MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR,
                'director_approved_at' => now(),
                'status' => 'pending',
                'current_stage' => MrfParallelFirstApprovalService::STATE,
                'workflow_state' => MrfParallelFirstApprovalService::STATE,
            ],
            $user,
            'verification',
        );

        $this->assertSame(1, DB::table('mrf_approval_history')->where('mrf_id', $mrf->id)->count());
        $this->assertNotNull(MRF::find($mrf->id)->director_approved_at);
        $this->assertSame(MrfParallelFirstApprovalService::ROLE_SUPPLY_CHAIN_DIRECTOR, MRF::find($mrf->id)->first_approval_by_role);
    }
}
