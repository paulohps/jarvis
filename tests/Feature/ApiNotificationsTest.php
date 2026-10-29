<?php

use App\Events\ShoppingListChanged;
use App\Models\Module;
use App\Models\Notification as StoredNotification;
use App\Models\ShoppingList;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone')->plainTextToken;
});

/**
 * @param  array<string, mixed>  $data
 */
function storeApiNotificationFor(User $user, array $data = [], bool $isRead = false, ?string $createdAt = null): StoredNotification
{
    return StoredNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'api-test-notice',
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Stored notice',
            'body' => '<p>Body</p><script>alert(1)</script>',
            'actions' => [
                ['label' => 'Open', 'url' => url('/reminders')],
                ['label' => 'Elsewhere', 'url' => 'https://evil.example.com'],
            ],
            ...$data,
        ],
        'read_at' => $isRead ? now() : null,
        'created_at' => $createdAt ?? now(),
    ]);
}

test('api notifications are paginated and scoped to the user', function () {
    foreach (range(1, 3) as $minutesAgo) {
        storeApiNotificationFor($this->user, ['title' => "Notice {$minutesAgo}"], createdAt: now()->subMinutes($minutesAgo)->toDateTimeString());
    }
    storeApiNotificationFor($this->user, ['title' => 'Old and read'], isRead: true, createdAt: now()->subDay()->toDateTimeString());
    storeApiNotificationFor(User::factory()->create(), ['title' => 'Someone else']);

    $firstPage = $this->withToken($this->token)->getJson('/api/v1/notifications?per_page=2');

    $firstPage->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.title', ['Notice 1', 'Notice 2'])
        ->assertJsonPath('data.0.body', '<p>Body</p>')
        ->assertJsonPath('data.0.url', url('/reminders'))
        ->assertJsonPath('data.0.actions', [['label' => 'Open', 'url' => url('/reminders')]])
        ->assertJsonPath('data.0.is_read', false)
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.unread_count', 3);

    $this->withToken($this->token)
        ->getJson('/api/v1/notifications?per_page=2&page=2')
        ->assertOk()
        ->assertJsonPath('data.*.title', ['Notice 3', 'Old and read'])
        ->assertJsonPath('data.1.is_read', true);

    $this->withToken($this->token)
        ->getJson('/api/v1/notifications?unread=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonMissing(['title' => 'Someone else']);
});

test('api marks one notification read', function () {
    $notification = storeApiNotificationFor($this->user);
    storeApiNotificationFor($this->user);

    $this->withToken($this->token)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertExactJson(['marked' => 1, 'unread_count' => 1]);

    expect($notification->fresh()->read_at)->not->toBeNull();

    // Already read: nothing more to mark.
    $this->withToken($this->token)
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertExactJson(['marked' => 0, 'unread_count' => 1]);
});

test('marking another users notification answers 404', function () {
    $theirs = storeApiNotificationFor(User::factory()->create());

    $this->withToken($this->token)
        ->postJson("/api/v1/notifications/{$theirs->id}/read")
        ->assertNotFound();

    $this->withToken($this->token)
        ->postJson('/api/v1/notifications/not-a-uuid/read')
        ->assertNotFound();

    expect($theirs->fresh()->read_at)->toBeNull();
});

test('api read all clears unread', function () {
    storeApiNotificationFor($this->user);
    storeApiNotificationFor($this->user);
    storeApiNotificationFor($this->user, isRead: true);
    $theirs = storeApiNotificationFor(User::factory()->create());

    $this->withToken($this->token)
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk()
        ->assertExactJson(['marked' => 2, 'unread_count' => 0]);

    expect($this->user->unreadNotifications()->count())->toBe(0)
        ->and($theirs->fresh()->read_at)->toBeNull();
});

test('notifications require a token', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
});

describe('broadcasting', function () {
    beforeEach(function () {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        Broadcast::purge('reverb');

        require base_path('routes/channels.php');
    });

    test('sanctum token can authorize private channels', function () {
        $this->withToken($this->token)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-App.Models.User.{$this->user->id}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $someoneElse = User::factory()->create();

        $this->withToken($this->token)
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-App.Models.User.{$someoneElse->id}",
            ])
            ->assertForbidden();
    });

    test('the channel me gives is the one the token can subscribe to', function () {
        $channel = $this->withToken($this->token)->getJson('/api/v1/me')->json('data.broadcast_channel');

        $this->withToken($this->token)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    });

    test('list channel without the module answers 403', function () {
        $list = ShoppingList::factory()->create();
        $channel = 'private-'.ShoppingListChanged::channelFor($list->friendly_id);
        $shopper = User::factory()->withModules([Module::SHOPPING])->create();

        $this->withToken($this->token)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertForbidden();

        app('auth')->forgetGuards();

        $this->withToken($shopper->createToken('Pixel')->plainTextToken)
            ->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    });

    test('channel authorization requires a token', function () {
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-App.Models.User.{$this->user->id}",
        ])->assertUnauthorized();
    });
});
