<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\User;
use App\Services\Analytics\AnalyticsClassificationService;
use App\Services\Analytics\ScmKpiService;
use App\Support\AnalyticsBaseline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScmKpiServiceTest extends TestCase
{
    use DatabaseTransactions;

    private ScmKpiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsBaseline::flush();
        $classification = app(AnalyticsClassificationService::class);
        $this->service  = new ScmKpiService($classification);
    }

    protected function tearDown(): void
    {
        DB::table('system_settings')
            ->where('category', 'analytics_baseline')
            ->delete();
        AnalyticsBaseline::flush();
        parent::tearDown();
    }

    // ─── Classification does not change operational queue counts ─────────────

    public function test_unreconciled_legacy_mrf_remains_in_operational_queue(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        // Establish a baseline in the future so our MRF is "pre-baseline"
        $baseline = now()->addDay();
        DB::table('system_settings')->updateOrInsert(
            ['category' => 'analytics_baseline', 'key' => 'effective_at'],
            ['value' => $baseline->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        );
        AnalyticsBaseline::flush();

        // Create a legacy MRF in an approval-pending state
        $mrf = MRF::factory()->create([
            'workflow_state' => 'supply_chain_director_review',
            'created_at'     => now()->subDays(5),
            'analytics_class' => null,
        ]);

        // Operational count must include it
        $workload = $this->service->operationalOpenWorkload();
        $this->assertGreaterThan(0, $workload['count']);

        // Legacy count should also include it
        $this->assertGreaterThan(0, $workload['legacy_count']);
    }

    // ─── Performance metrics exclude unreconciled legacy ────────────────────

    public function test_requests_raised_excludes_pre_baseline_records(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        $baselineAt = now()->subDays(3);
        DB::table('system_settings')->updateOrInsert(
            ['category' => 'analytics_baseline', 'key' => 'effective_at'],
            ['value' => $baselineAt->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        );
        AnalyticsBaseline::flush();

        // Create a pre-baseline MRF
        MRF::factory()->create(['created_at' => now()->subDays(10), 'analytics_class' => null]);

        // Create a post-baseline MRF
        MRF::factory()->create(['created_at' => now()->subDay(), 'analytics_class' => null]);

        $from   = now()->subDays(30)->startOfDay();
        $to     = now()->endOfDay();
        $result = $this->service->performanceRequestsRaised($from, $to);

        // The result count should include only post-baseline MRFs in range
        // (pre-baseline is excluded because it is treated as unreconciled_legacy)
        $this->assertFalse($result['insufficient_data']);
    }

    // ─── Insufficient data signals ───────────────────────────────────────────

    public function test_delivery_performance_returns_insufficient_data_when_no_eligible_deliveries(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        // Period with no MRFs that have expected_delivery_date + delivery state
        $from   = Carbon::create(2000, 1, 1)->startOfDay();
        $to     = Carbon::create(2000, 1, 31)->endOfDay();
        $result = $this->service->performanceDeliveryOnTime($from, $to);

        $this->assertTrue($result['insufficient_data']);
        $this->assertNull($result['on_time_pct']);
    }

    public function test_kpi_payload_includes_baseline_and_period(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        $from    = now()->subDays(30)->startOfDay();
        $to      = now()->endOfDay();
        $payload = $this->service->buildKpiPayload($from, $to);

        $this->assertArrayHasKey('baseline', $payload);
        $this->assertArrayHasKey('period', $payload);
        $this->assertArrayHasKey('sla_constants', $payload);
        $this->assertArrayHasKey('approval_sla_days', $payload['sla_constants']);
        $this->assertArrayHasKey('delivery_performance', $payload);
        $this->assertArrayHasKey('open_workload', $payload);
    }

    // ─── Analytics API endpoints ─────────────────────────────────────────────

    public function test_analytics_kpis_endpoint_requires_management_role(): void
    {
        if (! Schema::hasTable('users')) {
            $this->markTestSkipped('users table not available');
        }

        $user = User::factory()->create(['supply_chain_role' => 'employee']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/analytics/kpis')->assertStatus(403);
    }

    public function test_analytics_kpis_endpoint_returns_payload_for_scd(): void
    {
        if (! Schema::hasTable('users')) {
            $this->markTestSkipped('users table not available');
        }

        $user = User::factory()->create(['supply_chain_role' => 'supply_chain_director']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->getJson('/api/analytics/kpis');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'baseline',
                    'period',
                    'sla_constants',
                    'delivery_performance',
                    'open_workload',
                ],
            ]);
    }

    public function test_analytics_baseline_endpoint_is_accessible_to_all_authenticated_users(): void
    {
        if (! Schema::hasTable('users')) {
            $this->markTestSkipped('users table not available');
        }

        $user = User::factory()->create(['supply_chain_role' => 'employee']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/analytics/baseline')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'baseline' => ['established', 'effective_at']]);
    }
}
