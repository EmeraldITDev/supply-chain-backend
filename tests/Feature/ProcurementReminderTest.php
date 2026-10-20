<?php

namespace Tests\Feature;

use App\Models\MRF;
use App\Models\ProcurementReminderDispatch;
use App\Models\User;
use App\Notifications\ProcurementActionReminderNotification;
use App\Services\Analytics\ReminderRecipientResolver;
use App\Services\MrfParallelFirstApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcurementReminderTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Recipient resolution ─────────────────────────────────────────────────

    public function test_parallel_approval_notifies_only_pending_side(): void
    {
        if (! Schema::hasTable('m_r_f_s') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables not available');
        }

        // SCD has already approved; executive has not
        $mrf = MRF::factory()->create([
            'workflow_state'    => MrfParallelFirstApprovalService::STATE,
            'director_approved_at' => now()->subHour(),
            'executive_approved' => false,
        ]);

        $executive = User::factory()->create(['supply_chain_role' => 'executive']);
        $scd       = User::factory()->create(['supply_chain_role' => 'supply_chain_director']);

        $resolver    = app(ReminderRecipientResolver::class);
        $resolution  = $resolver->resolveForMrf($mrf);

        $this->assertFalse($resolution['assignment_gap'], 'Should have resolved a recipient');
        $recipientIds = $resolution['recipients']->pluck('id')->toArray();

        // Should include executive (pending) but NOT SCD (already approved)
        $this->assertContains($executive->id, $recipientIds);
        $this->assertNotContains($scd->id, $recipientIds);
    }

    public function test_resolver_flags_gap_when_no_users_hold_role(): void
    {
        if (! Schema::hasTable('m_r_f_s') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables not available');
        }

        // No users with the 'finance' role exist
        User::query()->where('supply_chain_role', 'finance')->delete();

        $mrf = MRF::factory()->create([
            'workflow_state' => 'finance_handoff_pending',
        ]);

        $resolver   = app(ReminderRecipientResolver::class);
        $resolution = $resolver->resolveForMrf($mrf);

        $this->assertTrue($resolution['assignment_gap']);
        $this->assertTrue($resolution['recipients']->isEmpty());
    }

    public function test_procurement_review_uses_assigned_manager_when_set(): void
    {
        if (! Schema::hasTable('m_r_f_s') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables not available');
        }

        $manager = User::factory()->create(['supply_chain_role' => 'procurement_manager']);

        $mrf = MRF::factory()->create([
            'workflow_state'        => 'procurement_review',
            'procurement_manager_id' => $manager->id,
        ]);

        $resolver   = app(ReminderRecipientResolver::class);
        $resolution = $resolver->resolveForMrf($mrf);

        $this->assertFalse($resolution['assignment_gap']);
        $this->assertCount(1, $resolution['recipients']);
        $this->assertEquals($manager->id, $resolution['recipients']->first()->id);
    }

    // ─── Deduplication ────────────────────────────────────────────────────────

    public function test_no_duplicate_within_same_period_key(): void
    {
        if (! Schema::hasTable('procurement_reminder_dispatches')) {
            $this->markTestSkipped('procurement_reminder_dispatches table not available');
        }

        $userId     = 999;
        $periodKey  = now()->format('Y-m-d');
        $mrf        = MRF::factory()->create(['workflow_state' => 'supply_chain_director_review']);

        // First dispatch
        ProcurementReminderDispatch::markSent($userId, MRF::class, $mrf->id, 'supply_chain_director_review', $periodKey);

        // Second check should detect dedup
        $this->assertTrue(
            ProcurementReminderDispatch::alreadySent($userId, MRF::class, $mrf->id, 'supply_chain_director_review', $periodKey),
        );
    }

    public function test_different_period_keys_are_not_deduped(): void
    {
        if (! Schema::hasTable('procurement_reminder_dispatches')) {
            $this->markTestSkipped('procurement_reminder_dispatches table not available');
        }

        $userId = 888;
        $mrf    = MRF::factory()->create(['workflow_state' => 'executive_review']);

        ProcurementReminderDispatch::markSent($userId, MRF::class, $mrf->id, 'executive_review', '2026-10-18');

        // Different day → allowed
        $this->assertFalse(
            ProcurementReminderDispatch::alreadySent($userId, MRF::class, $mrf->id, 'executive_review', '2026-10-19'),
        );
    }

    // ─── Deep link format ─────────────────────────────────────────────────────

    public function test_notification_deep_link_points_to_mrf_record(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        config(['app.frontend_url' => 'https://scm.emeraldcfze.com']);

        $mrf      = MRF::factory()->create(['workflow_state' => 'supply_chain_director_review']);
        $notif    = new ProcurementActionReminderNotification($mrf, 25);
        $notifiable = User::factory()->make(['name' => 'Test User']);

        $mail     = $notif->toMail($notifiable);
        $array    = $notif->toArray($notifiable);

        // action_url in database notification must include the MRF ID
        $this->assertStringContainsString($mrf->mrf_id, $array['action_url']);
        $this->assertStringStartsWith('https://scm.emeraldcfze.com/mrfs/', $array['action_url']);
    }

    public function test_po_signature_deep_link_includes_po_action(): void
    {
        if (! Schema::hasTable('m_r_f_s')) {
            $this->markTestSkipped('m_r_f_s table not available');
        }

        config(['app.frontend_url' => 'https://scm.emeraldcfze.com']);

        $mrf   = MRF::factory()->create(['workflow_state' => 'pending_scd_signature']);
        $notif = new ProcurementActionReminderNotification($mrf, 5);
        $array = $notif->toArray(User::factory()->make());

        $this->assertStringContainsString('tab=po', $array['action_url']);
        $this->assertStringContainsString('action=sign', $array['action_url']);
    }

    // ─── Artisan command smoke test ───────────────────────────────────────────

    public function test_reminder_command_dry_run_succeeds(): void
    {
        Notification::fake();

        $this->artisan('scm:send-approval-reminders', [
            '--hours'    => 24,
            '--dry-run'  => true,
        ])->assertSuccessful();

        Notification::assertNothingSent();
    }
}
