<?php

namespace Tests\Feature;

use App\Console\Commands\EstablishAnalyticsBaseline;
use App\Support\AnalyticsBaseline;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyticsBaselineTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsBaseline::flush();
    }

    protected function tearDown(): void
    {
        // Clean up sentinel row inserted by tests
        DB::table('system_settings')
            ->where('category', 'analytics_baseline')
            ->delete();
        AnalyticsBaseline::flush();
        parent::tearDown();
    }

    public function test_baseline_not_established_when_no_value_set(): void
    {
        if (! Schema::hasTable('system_settings')) {
            $this->markTestSkipped('system_settings table not available');
        }

        DB::table('system_settings')
            ->where('category', 'analytics_baseline')
            ->where('key', 'effective_at')
            ->delete();

        $this->assertFalse(AnalyticsBaseline::isEstablished());
        $this->assertNull(AnalyticsBaseline::effectiveAt());
    }

    public function test_baseline_is_established_after_command(): void
    {
        if (! Schema::hasTable('system_settings')) {
            $this->markTestSkipped('system_settings table not available');
        }

        $at = '2026-10-19T08:00:00+01:00';

        $this->artisan('scm:establish-analytics-baseline', [
            '--at'       => $at,
            '--timezone' => 'Africa/Lagos',
            '--version'  => 'test-sha',
            '--no-interaction' => true,
        ])->assertSuccessful();

        AnalyticsBaseline::flush();

        $this->assertTrue(AnalyticsBaseline::isEstablished());
        $this->assertNotNull(AnalyticsBaseline::effectiveAt());
    }

    public function test_baseline_command_refuses_second_run_without_force(): void
    {
        if (! Schema::hasTable('system_settings')) {
            $this->markTestSkipped('system_settings table not available');
        }

        // Set an existing value
        DB::table('system_settings')->updateOrInsert(
            ['category' => 'analytics_baseline', 'key' => 'effective_at'],
            ['value' => now()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        );
        AnalyticsBaseline::flush();

        $this->artisan('scm:establish-analytics-baseline', [
            '--at'             => now()->toIso8601String(),
            '--no-interaction' => true,
        ])->assertFailed();
    }

    public function test_baseline_config_returns_expected_keys(): void
    {
        if (! Schema::hasTable('system_settings')) {
            $this->markTestSkipped('system_settings table not available');
        }

        $config = AnalyticsBaseline::config();

        $this->assertArrayHasKey('established', $config);
        $this->assertArrayHasKey('effective_at', $config);
        $this->assertArrayHasKey('timezone', $config);
    }
}
