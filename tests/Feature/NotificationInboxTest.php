<?php

use App\Actions\Notifications\MarkNotificationsRead;
use App\Events\NotificationCreated;
use App\Models\Notification as StoredNotification;
use App\Models\User;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationFeed;
use App\Services\Notifications\NotificationProvisioner;
use Database\Seeders\LookupSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Fixtures\Notifications\AccountNotice;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [AccountNotice::class]]);

    app(NotificationProvisioner::class)->provisionAll();
});

/**
 * @param  array<string, mixed>  $data
 */
function storeNotificationFor(User $user, array $data = [], bool $isRead = false): StoredNotification
{
    return StoredNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => AccountNotice::class,
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Stored notice',
            'body' => '<p>Body</p>',
            'actions' => [['label' => 'Open', 'url' => url('/reminders')]],
            ...$data,
        ],
        'read_at' => $isRead ? now() : null,
    ]);
}

test('database notification broadcasts to the owner private channel', function () {
    Event::fake([NotificationCreated::class]);

    $owner = User::factory()->create();

    app(NotificationCenter::class)->dispatch(new AccountNotice('Bills'), $owner);
    app(NotificationCenter::class)->dispatch(new AccountNotice('Rent'), $owner);

    $stored = $owner->notifications()->get();

    Event::assertDispatchedTimes(NotificationCreated::class, 2);
    Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($owner, $stored): bool {
        $channels = $event->broadcastOn();

        return count($channels) === 1
            && $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-App.Models.User.'.$owner->id
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith() === [
                'id' => $stored->firstWhere('data.title', 'Notice: Rent')->id,
                'title' => 'Notice: Rent',
                'unread_count' => 2,
            ];
    });
});

test('user cannot mark another users notification as read', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $notification = storeNotificationFor($owner);

    $this->actingAs($intruder)
        ->get(route('notifications.open', $notification->id))
        ->assertNotFound();

    expect(fn () => app(MarkNotificationsRead::class)($intruder, $notification->id))
        ->toThrow(ModelNotFoundException::class)
        ->and($notification->fresh()->read_at)->toBeNull();
});

test('opening a notification marks it read and follows its action', function () {
    $user = User::factory()->create();
    $notification = storeNotificationFor($user, ['actions' => [
        ['label' => 'First', 'url' => url('/reminders')],
        ['label' => 'Second', 'url' => url('/shopping')],
    ]]);

    $this->actingAs($user)
        ->get(route('notifications.open', ['notification' => $notification->id, 'action' => 1]))
        ->assertRedirect(url('/shopping'));

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('mark all as read clears unread count', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    storeNotificationFor($user);
    storeNotificationFor($user);
    storeNotificationFor($user, isRead: true);
    $othersNotification = storeNotificationFor($other);

    $feed = app(NotificationFeed::class);

    expect($feed->unreadCount($user))->toBe(2)
        ->and(app(MarkNotificationsRead::class)($user))->toBe(2)
        ->and($feed->unreadCount($user))->toBe(0)
        ->and($othersNotification->fresh()->read_at)->toBeNull();
});

test('marking one notification read leaves the others unread', function () {
    $user = User::factory()->create();
    $first = storeNotificationFor($user);
    storeNotificationFor($user);

    expect(app(MarkNotificationsRead::class)($user, $first->id))->toBe(1)
        ->and(app(NotificationFeed::class)->unreadCount($user))->toBe(1);
});

test('notification feed is paginated newest first and filters unread', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $index) {
        $this->travel(1)->minutes();
        storeNotificationFor($user, ['title' => "Notice {$index}"], isRead: $index <= 5);
    }

    storeNotificationFor(User::factory()->create());

    $feed = app(NotificationFeed::class);
    $page = $feed->paginate($user, unreadOnly: false, perPage: 20);
    $unread = $feed->paginate($user, unreadOnly: true, perPage: 20);

    expect($page->total())->toBe(25)
        ->and($page->items())->toHaveCount(20)
        ->and($page->items()[0]->title)->toBe('Notice 25')
        ->and($unread->total())->toBe(20)
        ->and($feed->latest($user, 10))->toHaveCount(10);
});

test('notification feed drops actions that leave the application', function () {
    $user = User::factory()->create();
    storeNotificationFor($user, [
        'body' => '<p onclick="steal()">Body</p><script>alert(1)</script>',
        'actions' => [
            ['label' => 'Inside', 'url' => url('/reminders')],
            ['label' => 'Outside', 'url' => 'https://evil.example.com/phish'],
            ['label' => 'Protocol relative', 'url' => '//evil.example.com'],
        ],
    ]);

    $item = app(NotificationFeed::class)->latest($user, 10)->sole();

    expect($item->actions)->toBe([['label' => 'Inside', 'url' => url('/reminders')]])
        ->and($item->body)->toBe('<p>Body</p>');
});
