<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\SystemAnnouncementNotification;
use App\Support\DatabaseNotifications;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseNotificationsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_database_notifications_persist_without_mail_channel(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables are not available');
        }

        $user = User::factory()->create();

        DatabaseNotifications::send($user, new SystemAnnouncementNotification(
            'Test title',
            'Test message body',
            '/test',
            'normal'
        ));

        $this->assertSame(1, $user->notifications()->count());

        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->first();

        $this->assertSame('Test title', $notification->data['title']);
        $this->assertSame('Test message body', $notification->data['message']);
        $this->assertNull($notification->read_at);
    }
}
