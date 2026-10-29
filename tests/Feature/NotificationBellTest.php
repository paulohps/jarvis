<?php

use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\NotificationHistory;
use App\Models\Notification as StoredNotification;
use App\Models\User;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fixtures\Notifications\AccountNotice;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
});

/**
 * @param  array<string, mixed>  $data
 */
function bellNotificationFor(User $user, array $data = [], bool $isRead = false): StoredNotification
{
    return StoredNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => AccountNotice::class,
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => [
            'title' => 'Reminder due',
            'body' => '<p>Pay the rent</p>',
            'actions' => [['label' => 'Open', 'url' => url('/reminders')]],
            ...$data,
        ],
        'read_at' => $isRead ? now() : null,
    ]);
}

test('the app shell shows the bell to the signed in user', function () {
    $user = User::factory()->create();
    bellNotificationFor($user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire(Bell::class)
        ->assertSee('data-unread-count', false);
});

test('bell shows the unread count and lists the latest ten when opened', function () {
    $user = User::factory()->create();

    foreach (range(1, 12) as $index) {
        $this->travel(1)->minutes();
        bellNotificationFor($user, ['title' => "Notice {$index}"]);
    }

    $component = Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertSet('unreadCount', 12)
        ->assertDontSee('Notice 12')
        ->call('loadItems')
        ->assertSee('Notice 12')
        ->assertSee('Notice 3')
        ->assertSee(route('notifications.open', $user->notifications()->first()->id));

    expect(substr_count($component->html(), 'data-notification="'))->toBe(Bell::PREVIEW_LIMIT)
        ->and($component->html())->not->toMatch('/Notice 2\s*</');
});

test('a new notification increments the counter without refresh', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertSet('unreadCount', 0);

    $notification = bellNotificationFor($user);

    $component
        ->dispatch("echo-private:App.Models.User.{$user->id},.notification.created", [
            'id' => $notification->id,
            'title' => 'Reminder due',
            'unread_count' => 1,
        ])
        ->assertSet('unreadCount', 1);
});

test('bell marks all notifications as read', function () {
    $user = User::factory()->create();
    bellNotificationFor($user);
    bellNotificationFor($user);

    Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertSet('unreadCount', 2)
        ->call('markAllAsRead')
        ->assertSet('unreadCount', 0);

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('bell shows 99+ above ninety nine unread notifications', function () {
    $user = User::factory()->create();

    foreach (range(1, 100) as $index) {
        bellNotificationFor($user);
    }

    Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertSee('99+');
});

test('notification action navigates to the link of the type', function () {
    $user = User::factory()->create();
    $notification = bellNotificationFor($user, ['actions' => [['label' => 'See reminders', 'url' => url('/reminders')]]]);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('See reminders')
        ->assertSee(route('notifications.open', ['notification' => $notification->id, 'action' => 0]), false);

    $this->actingAs($user)
        ->get(route('notifications.open', ['notification' => $notification->id, 'action' => 0]))
        ->assertRedirect(url('/reminders'));
});

test('notification history is paginated and filters unread', function () {
    $user = User::factory()->create();

    foreach (range(1, 22) as $index) {
        $this->travel(1)->minutes();
        bellNotificationFor($user, ['title' => "Notice {$index}"], isRead: $index <= 2);
    }

    $component = Livewire::actingAs($user)
        ->test(NotificationHistory::class)
        ->assertSee('Notice 22');

    expect(substr_count($component->html(), 'data-notification="'))->toBe(NotificationHistory::PER_PAGE)
        ->and($component->html())->not->toMatch('/Notice 2\s*</');

    $component->set('filter', NotificationHistory::UNREAD);

    expect(substr_count($component->html(), 'data-notification="'))->toBe(20)
        ->and($component->html())->not->toMatch('/Notice 1\s*</');

    $component->call('markAsRead', $user->notifications()->first()->id);

    expect($user->unreadNotifications()->count())->toBe(19);

    $component->call('markAllAsRead');

    expect($user->unreadNotifications()->count())->toBe(0);
});

test('notification history only shows the users own notifications', function () {
    $user = User::factory()->create();
    bellNotificationFor(User::factory()->create(), ['title' => 'Someone else notice']);

    $this->actingAs($user)
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('Someone else notice')
        ->assertSee(__('You have no notifications yet.'));
});
