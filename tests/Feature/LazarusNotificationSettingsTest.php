<?php

namespace Tests\Feature;

use App\Listeners\SuppressLazarusMailUnlessEnabled;
use App\Mail\POSignedMail;
use App\Models\User;
use App\Notifications\MRFSubmittedNotification;
use App\Support\LazarusNotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class LazarusNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LazarusNotificationSettings::flush();
    }

    public function test_lazarus_mail_is_blocked_until_a_setting_is_turned_on(): void
    {
        $listener = new SuppressLazarusMailUnlessEnabled;

        $blocked = $this->message(POSignedMail::class);
        $this->assertFalse($listener->handle(new MessageSending($blocked, [
            '__laravel_mailable' => POSignedMail::class,
        ])));

        $unmapped = (new Email)
            ->from('scm@example.com')
            ->to(LazarusNotificationSettings::EMAIL)
            ->subject('Trip confirmed')
            ->text('Hello');
        $this->assertFalse($listener->handle(new MessageSending($unmapped, [
            '__laravel_notification' => 'App\\Mail\\TripRequestDirectorApprovedMail',
        ])));

        LazarusNotificationSettings::save(['mrf_submitted' => true]);

        $allowed = $this->message(MRFSubmittedNotification::class);
        $this->assertNull($listener->handle(new MessageSending($allowed, [
            '__laravel_notification' => MRFSubmittedNotification::class,
        ])));
        $this->assertSame(
            LazarusNotificationSettings::EMAIL,
            $allowed->getTo()[0]->getAddress()
        );

        $passwordReset = (new Email)
            ->from('scm@example.com')
            ->to(LazarusNotificationSettings::EMAIL)
            ->subject('Password Reset - Emerald')
            ->text('Temporary password');
        $this->assertNull($listener->handle(new MessageSending($passwordReset)));
        $this->assertCount(1, $passwordReset->getTo());
    }

    public function test_settings_default_off_and_only_supply_chain_or_procurement_can_change_them(): void
    {
        $employee = User::factory()->create(['supply_chain_role' => 'employee']);
        Sanctum::actingAs($employee);

        $this->getJson('/api/settings/lazarus-notifications')->assertForbidden();
        $this->putJson('/api/settings/lazarus-notifications', [
            'toggles' => ['mrf_submitted' => true],
        ])->assertForbidden();

        $director = User::factory()->create(['supply_chain_role' => 'supply_chain_director']);
        Sanctum::actingAs($director);

        $this->getJson('/api/settings/lazarus-notifications')
            ->assertOk()
            ->assertJsonPath('data.toggles.0.enabled', false)
            ->assertJsonPath('data.toggles.0.key', 'mrf_submitted');

        $this->putJson('/api/settings/lazarus-notifications', [
            'toggles' => ['po_signed' => true],
        ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(LazarusNotificationSettings::enabled('po_signed'));
        $this->assertFalse(LazarusNotificationSettings::enabled('mrf_submitted'));
    }

    private function message(string $class): Email
    {
        return (new Email)
            ->from('scm@example.com')
            ->to(LazarusNotificationSettings::EMAIL)
            ->subject($class)
            ->text('Hello');
    }
}
