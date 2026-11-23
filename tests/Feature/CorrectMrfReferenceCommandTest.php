<?php

namespace Tests\Feature;

use App\Console\Commands\CorrectMrfReference;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\MRF;
use App\Models\User;
use App\Services\WorkflowStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CorrectMrfReferenceCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeTarget(array $overrides = []): MRF
    {
        return MRF::create(array_merge([
            'mrf_id' => CorrectMrfReference::OLD_REFERENCE,
            'formatted_id' => CorrectMrfReference::OLD_REFERENCE,
            'title' => CorrectMrfReference::EXPECTED_TITLE,
            'requester_id' => User::factory()->create()->id,
            'requester_name' => 'Tester',
            'department' => CorrectMrfReference::EXPECTED_DEPARTMENT,
            'category' => CorrectMrfReference::EXPECTED_CATEGORY,
            'estimated_cost' => CorrectMrfReference::EXPECTED_ESTIMATED_COST,
            'date' => now(),
            'status' => CorrectMrfReference::EXPECTED_STATUS,
            'current_stage' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PENDING_REVISION,
            'revision_number' => 1,
            'source' => 'standard',
        ], $overrides));
    }

    public function test_defaults_to_dry_run_and_does_not_change_reference(): void
    {
        $mrf = $this->makeTarget();

        $this->artisan('scm:correct-mrf-reference')
            ->assertExitCode(0);

        $fresh = $mrf->fresh();
        $this->assertSame(CorrectMrfReference::OLD_REFERENCE, $fresh->formatted_id);
        $this->assertSame(CorrectMrfReference::OLD_REFERENCE, $fresh->mrf_id);
        $this->assertSame(CorrectMrfReference::EXPECTED_STATUS, $fresh->status);
    }

    public function test_apply_updates_authoritative_reference_and_writes_audit(): void
    {
        $mrf = $this->makeTarget();

        if (Schema::hasTable('activities')) {
            Activity::create([
                'type' => 'mrf_created',
                'title' => 'MRF created',
                'description' => 'Created '.CorrectMrfReference::OLD_REFERENCE,
                'user_id' => $mrf->requester_id,
                'user_name' => 'Tester',
                'entity_type' => 'mrf',
                'entity_id' => CorrectMrfReference::OLD_REFERENCE,
                'status' => 'completed',
                'created_at' => now(),
            ]);
        }

        $this->artisan('scm:correct-mrf-reference', ['--apply' => true])
            ->assertExitCode(0);

        $fresh = $mrf->fresh();
        $this->assertSame(CorrectMrfReference::NEW_REFERENCE, $fresh->formatted_id);
        $this->assertSame(CorrectMrfReference::NEW_REFERENCE, $fresh->mrf_id);
        $this->assertSame(CorrectMrfReference::EXPECTED_STATUS, $fresh->status);
        $this->assertSame(WorkflowStateService::STATE_PENDING_REVISION, $fresh->workflow_state);
        $this->assertSame(1, (int) $fresh->revision_number);
        $this->assertEquals(CorrectMrfReference::EXPECTED_ESTIMATED_COST, (float) $fresh->estimated_cost);

        if (Schema::hasTable('activities')) {
            $this->assertSame(
                0,
                Activity::query()->where('entity_id', CorrectMrfReference::OLD_REFERENCE)->count()
            );
            $this->assertSame(
                1,
                Activity::query()->where('entity_id', CorrectMrfReference::NEW_REFERENCE)->count()
            );
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mrf_reference_corrected',
            'entity_type' => 'MRF',
            'entity_id' => CorrectMrfReference::NEW_REFERENCE,
        ]);
    }

    public function test_refuses_identity_mismatch(): void
    {
        $this->makeTarget(['title' => 'SOME OTHER TITLE']);

        $this->artisan('scm:correct-mrf-reference', ['--apply' => true])
            ->assertExitCode(1);
    }

    public function test_refuses_when_new_reference_already_exists_on_another_row(): void
    {
        $this->makeTarget();
        $this->makeTarget([
            'mrf_id' => CorrectMrfReference::NEW_REFERENCE.'-OTHER',
            'formatted_id' => CorrectMrfReference::NEW_REFERENCE,
            'title' => 'Unrelated MRF',
            'status' => 'procurement',
            'workflow_state' => WorkflowStateService::STATE_PROCUREMENT_REVIEW,
        ]);

        $this->artisan('scm:correct-mrf-reference', ['--apply' => true])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
    }

    public function test_idempotent_when_already_corrected(): void
    {
        $mrf = $this->makeTarget([
            'mrf_id' => CorrectMrfReference::NEW_REFERENCE,
            'formatted_id' => CorrectMrfReference::NEW_REFERENCE,
        ]);

        $this->artisan('scm:correct-mrf-reference', ['--apply' => true])
            ->expectsOutputToContain('already corrected')
            ->assertExitCode(0);

        $this->assertSame(CorrectMrfReference::NEW_REFERENCE, $mrf->fresh()->formatted_id);
    }

    public function test_updates_formatted_id_only_when_legacy_mrf_id_differs(): void
    {
        $mrf = $this->makeTarget([
            'mrf_id' => 'MRF-OANDO-2026-256',
            'formatted_id' => CorrectMrfReference::OLD_REFERENCE,
        ]);

        $this->artisan('scm:correct-mrf-reference', ['--apply' => true])
            ->assertExitCode(0);

        $fresh = $mrf->fresh();
        $this->assertSame('MRF-OANDO-2026-256', $fresh->mrf_id);
        $this->assertSame(CorrectMrfReference::NEW_REFERENCE, $fresh->formatted_id);
    }
}
