<?php

namespace Tests\Unit;

use App\Models\MRF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MrfPendingForRoleScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('mrf_approval_history');
        Schema::dropIfExists('m_r_f_s');

        Schema::create('m_r_f_s', function ($table): void {
            $table->id();
            $table->string('mrf_id')->nullable();
            $table->string('workflow_state')->nullable();
            $table->string('current_stage')->nullable();
            $table->string('status')->nullable();
            $table->string('first_approval_by_role')->nullable();
            $table->boolean('executive_approved')->nullable();
            $table->timestamp('director_approved_at')->nullable();
            $table->timestamp('scd_approved_at')->nullable();
            $table->string('unsigned_po_url')->nullable();
            $table->string('signed_po_url')->nullable();
            $table->timestamp('po_signed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mrf_approval_history', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('mrf_id');
            $table->string('action');
            $table->string('stage');
            $table->unsignedBigInteger('performed_by')->default(0);
            $table->string('performer_name')->nullable();
            $table->string('performer_role')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    #[Test]
    public function it_keeps_a_parallel_mrf_that_nobody_has_approved_yet(): void
    {
        $this->insertMrf([
            'mrf_id' => 'MRF-WAITING',
            'workflow_state' => 'parallel_first_approval',
            'current_stage' => 'parallel_first_approval',
            'status' => 'pending',
        ]);

        $this->assertSame(['MRF-WAITING'], $this->idsFor('scd'));
        $this->assertSame(['MRF-WAITING'], $this->idsFor('executive'));
    }

    #[Test]
    public function it_hides_an_mrf_once_workflow_state_has_left_the_approval_gate(): void
    {
        $this->insertMrf([
            'mrf_id' => 'MRF-APPROVED',
            'workflow_state' => 'supply_chain_director_approved',
            'current_stage' => 'parallel_first_approval',
            'status' => 'procurement_review',
            'first_approval_by_role' => 'supply_chain_director',
            'director_approved_at' => now(),
            'scd_approved_at' => now(),
        ]);

        $this->insertMrf([
            'mrf_id' => 'MRF-PROCUREMENT',
            'workflow_state' => 'procurement_review',
            'current_stage' => 'procurement_review',
            'status' => 'procurement_review',
            'first_approval_by_role' => 'executive',
            'executive_approved' => true,
        ]);

        $this->assertSame([], $this->idsFor('scd'));
        $this->assertSame([], $this->idsFor('executive'));
    }

    #[Test]
    public function it_treats_pending_for_role_scd_as_the_supply_chain_director(): void
    {
        $id = $this->insertMrf([
            'mrf_id' => 'MRF-SCD-SIGNED',
            'workflow_state' => 'parallel_first_approval',
            'current_stage' => 'parallel_first_approval',
            'status' => 'pending',
            'first_approval_by_role' => 'supply_chain_director',
            'director_approved_at' => now(),
        ]);

        DB::table('mrf_approval_history')->insert([
            'mrf_id' => $id,
            'action' => 'approved',
            'stage' => 'parallel_first_approval',
            'performed_by' => 1,
            'performer_name' => 'Director',
            'performer_role' => 'supply_chain_director',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->insertMrf([
            'mrf_id' => 'MRF-STILL-OPEN',
            'workflow_state' => 'parallel_first_approval',
            'current_stage' => 'parallel_first_approval',
            'status' => 'pending',
        ]);

        $this->assertSame(['MRF-STILL-OPEN'], $this->idsFor('scd'));
        $this->assertSame(['MRF-SCD-SIGNED', 'MRF-STILL-OPEN'], $this->idsFor('executive'));
    }

    #[Test]
    public function it_still_lists_a_purchase_order_waiting_for_the_director_signature(): void
    {
        $this->insertMrf([
            'mrf_id' => 'MRF-PO',
            'workflow_state' => 'po_generated',
            'current_stage' => 'supply_chain',
            'status' => 'pending_scd_signature',
            'unsigned_po_url' => 'https://example.test/po.pdf',
        ]);

        $this->assertSame(['MRF-PO'], $this->idsFor('scd'));
        $this->assertSame([], $this->idsFor('executive'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertMrf(array $attributes): int
    {
        return (int) DB::table('m_r_f_s')->insertGetId(array_merge([
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    /**
     * @return list<string>
     */
    private function idsFor(string $role): array
    {
        return MRF::query()
            ->pendingForRole($role)
            ->orderBy('id')
            ->pluck('mrf_id')
            ->all();
    }
}
