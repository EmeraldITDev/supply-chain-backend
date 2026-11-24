<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\MRFApprovalHistory;
use App\Models\User;
use App\Services\Finance\FinancePackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancePackageApprovalsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_approvals_summary_includes_scd_executive_chairman_names_and_history(): void
    {
        $mrf = $this->createMrf();

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'Ada SCD',
            'performer_role' => 'supply_chain_director',
            'remarks' => 'SCD sign-off',
            'created_at' => now()->subDays(2),
        ]);
        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'executive_review',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'Ben Executive',
            'performer_role' => 'executive',
            'created_at' => now()->subDay(),
        ]);
        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'chairman_review',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'Chi Chairman',
            'performer_role' => 'chairman',
            'created_at' => now(),
        ]);

        $summary = $this->approvalsSummary($mrf);

        $this->assertCount(3, $summary);
        $this->assertSame('scd', $summary[0]['authority']);
        $this->assertSame('SCD', $summary[0]['authorityLabel']);
        $this->assertSame('Ada SCD', $summary[0]['performerName']);
        $this->assertTrue($summary[0]['performerNameAvailable']);
        $this->assertSame('executive', $summary[1]['authority']);
        $this->assertSame('Ben Executive', $summary[1]['performerName']);
        $this->assertSame('chairman', $summary[2]['authority']);
        $this->assertSame('Chi Chairman', $summary[2]['performerName']);
        $this->assertSame('approved', $summary[0]['status']);
        $this->assertNotEmpty($summary[0]['timestamp']);
        $this->assertNotEmpty($summary[0]['eventReference']);
    }

    public function test_chairman_payment_is_not_mapped_as_chairman_procurement_authority(): void
    {
        $mrf = $this->createMrf();

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'payment_approved',
            'stage' => 'chairman_payment',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'Payment Chairman',
            'performer_role' => 'chairman',
        ]);

        $summary = $this->approvalsSummary($mrf);

        $this->assertCount(1, $summary);
        $this->assertSame('chairman_payment', $summary[0]['stage']);
        $this->assertSame('other', $summary[0]['authority']);
        $this->assertSame('Payment Chairman', $summary[0]['performerName']);
    }

    public function test_approvals_summary_marks_missing_performer_name(): void
    {
        $mrf = $this->createMrf();

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'executive_review',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => null,
            'performer_role' => 'executive',
        ]);

        $summary = $this->approvalsSummary($mrf);

        $this->assertCount(1, $summary);
        $this->assertSame('executive', $summary[0]['authority']);
        $this->assertNull($summary[0]['performerName']);
        $this->assertFalse($summary[0]['performerNameAvailable']);
    }

    public function test_parallel_first_approval_preserves_authority_distinction(): void
    {
        $mrf = $this->createMrf();

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'SCD Person',
            'performer_role' => 'supply_chain_director',
        ]);
        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => User::factory()->create()->id,
            'performer_name' => 'Exec Person',
            'performer_role' => 'executive',
        ]);

        $summary = $this->approvalsSummary($mrf);

        $this->assertSame(['scd', 'executive'], array_column($summary, 'authority'));
        $this->assertNotSame($summary[0]['authority'], $summary[1]['authority']);
    }

    public function test_parallel_first_approval_by_admin_maps_to_scd(): void
    {
        $mrf = $this->createMrf();

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => User::factory()->create(['name' => 'Ada Admin'])->id,
            'performer_name' => 'Ada Admin',
            'performer_role' => 'admin',
        ]);

        $summary = $this->approvalsSummary($mrf);

        $this->assertCount(1, $summary);
        $this->assertSame('scd', $summary[0]['authority']);
        $this->assertSame('Ada Admin', $summary[0]['performerName']);
    }

    public function test_po_sign_off_summary_reports_signed_director_with_name(): void
    {
        $signer = User::factory()->create(['name' => 'Chi Director']);
        $mrf = $this->createMrf([
            'status' => 'po_signed',
            'workflow_state' => 'po_signed',
            'signed_po_url' => 'https://s3.example/signed.pdf',
            'po_signed_at' => now(),
        ]);

        MRFApprovalHistory::create([
            'mrf_id' => $mrf->id,
            'action' => 'signed_po',
            'stage' => 'supply_chain',
            'performed_by' => $signer->id,
            'performer_name' => 'Chi Director',
            'performer_role' => 'supply_chain_director',
        ]);

        $builder = app(\App\Services\Finance\FinancePackageBuilder::class);
        $signOff = $builder->buildPoSignOffSummary($mrf);

        $this->assertSame('signed', $signOff['status']);
        $this->assertTrue($signOff['signed']);
        $this->assertSame('Chi Director', $signOff['signerName']);
        $this->assertTrue($signOff['signerNameAvailable']);
        $this->assertNotEmpty($signOff['signedAt']);
    }

    public function test_po_sign_off_summary_reports_awaiting_signature(): void
    {
        $mrf = $this->createMrf([
            'status' => 'awaiting_scd_signature',
            'workflow_state' => 'pending_scd_signature',
            'unsigned_po_url' => 'https://s3.example/unsigned.pdf',
            'signed_po_url' => null,
            'po_signed_at' => null,
        ]);

        $builder = app(\App\Services\Finance\FinancePackageBuilder::class);
        $signOff = $builder->buildPoSignOffSummary($mrf);

        $this->assertSame('awaiting', $signOff['status']);
        $this->assertTrue($signOff['awaitingSignature']);
        $this->assertFalse($signOff['signed']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalsSummary(MRF $mrf): array
    {
        $builder = app(FinancePackageBuilder::class);
        $method = new \ReflectionMethod($builder, 'buildApprovalsSummary');
        $method->setAccessible(true);

        return $method->invoke($builder, $mrf);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createMrf(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => 'MRF-APPR-'.Str::upper(Str::random(6)),
            'title' => 'Approval package test',
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Requester',
            'department' => 'Operations',
            'date' => now(),
            'created_at' => now(),
            'status' => 'completed',
            'workflow_state' => 'finance_in_review',
            'estimated_cost' => 1000,
            'scm_transaction_id' => (string) Str::uuid(),
            'po_number' => 'PO-APPR-TEST',
        ], $overrides));
    }
}
