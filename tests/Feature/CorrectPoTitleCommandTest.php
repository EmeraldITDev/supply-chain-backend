<?php

namespace Tests\Feature;

use App\Console\Commands\CorrectPoTitle;
use App\Models\MRF;
use App\Models\User;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectPoTitleCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTarget(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => CorrectPoTitle::TARGET_MRF_ID,
            'po_number' => CorrectPoTitle::TARGET_PO_NUMBER,
            'title' => CorrectPoTitle::EXPECTED_OLD_TITLE,
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => 'Procurement',
            'date' => now(),
            'status' => 'procurement',
            'current_stage' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
            'po_draft_saved_at' => now(),
            'unsigned_po_url' => null,
            'signed_po_url' => null,
            'source' => 'po_generated',
            'is_po_linked' => true,
        ], $overrides));
    }

    public function test_defaults_to_dry_run_and_does_not_change_title(): void
    {
        $mrf = $this->makeTarget();

        $this->artisan('scm:correct-po-title')
            ->assertExitCode(0);

        $this->assertSame(CorrectPoTitle::EXPECTED_OLD_TITLE, $mrf->fresh()->title);
    }

    public function test_apply_updates_title_when_draft_editable(): void
    {
        $mrf = $this->makeTarget();

        $this->artisan('scm:correct-po-title', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(CorrectPoTitle::NEW_TITLE, $mrf->fresh()->title);
    }

    public function test_refuses_wrong_title(): void
    {
        $this->makeTarget(['title' => 'SOME OTHER TITLE']);

        $this->artisan('scm:correct-po-title', ['--apply' => true])
            ->assertExitCode(1);
    }

    public function test_refuses_signed_po_and_requires_revision_workflow(): void
    {
        $mrf = $this->makeTarget([
            'unsigned_po_url' => 'https://example.test/unsigned.pdf',
            'signed_po_url' => 'https://example.test/signed.pdf',
            'po_signed_at' => now(),
            'status' => 'signed',
            'workflow_state' => WorkflowStateService::STATE_PO_SIGNED,
            'po_draft_saved_at' => null,
        ]);

        $this->artisan('scm:correct-po-title', ['--apply' => true])
            ->expectsOutputToContain('revision workflow')
            ->assertExitCode(1);

        $this->assertSame(CorrectPoTitle::EXPECTED_OLD_TITLE, $mrf->fresh()->title);
    }

    public function test_idempotent_when_already_corrected(): void
    {
        $mrf = $this->makeTarget(['title' => CorrectPoTitle::NEW_TITLE]);

        $this->artisan('scm:correct-po-title', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(CorrectPoTitle::NEW_TITLE, $mrf->fresh()->title);
    }

    public function test_refuses_identifier_mismatch(): void
    {
        $this->makeTarget(['po_number' => 'PO-OTHER']);

        $this->artisan('scm:correct-po-title')
            ->assertExitCode(1);
    }
}
