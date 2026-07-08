<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SystemAnnouncementNotification;
use App\Support\DatabaseNotifications;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_notifications_index_returns_uniform_payload(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables are not available');
        }

        $user = User::factory()->create();
        DatabaseNotifications::send($user, new SystemAnnouncementNotification(
            'Trip update',
            'Trip request TRQ-20260708-ABC was submitted.',
            '/trip-requests/1',
            'normal'
        ));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    [
                        'id',
                        'type',
                        'title',
                        'message',
                        'read',
                        'created_at',
                    ],
                ],
                'unread_count',
            ]);

        $item = $response->json('data.0');
        $this->assertFalse($item['read']);
        $this->assertSame('Trip update', $item['title']);
    }

    public function test_mark_notification_as_read_endpoint(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables are not available');
        }

        $user = User::factory()->create();
        DatabaseNotifications::send($user, new SystemAnnouncementNotification('Read test', 'Body', '/test'));

        /** @var DatabaseNotification $notification */
        $notification = $user->notifications()->first();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/notifications/' . $notification->id . '/read');

        $response->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_notifications_as_read_endpoint(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('users')) {
            $this->markTestSkipped('Required tables are not available');
        }

        $user = User::factory()->create();
        DatabaseNotifications::send($user, new SystemAnnouncementNotification('One', 'Body one', '/one'));
        DatabaseNotifications::send($user, new SystemAnnouncementNotification('Two', 'Body two', '/two'));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/notifications/mark-all-read');

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(0, $user->unreadNotifications()->count());
    }
}
