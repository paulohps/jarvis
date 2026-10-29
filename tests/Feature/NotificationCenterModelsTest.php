<?php

use App\Models\Locale;
use App\Models\Notification;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Support\NotificationSettingsCache;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->type = NotificationType::query()->create(['slug' => NotificationType::REMINDER_DUE]);
    $this->channel = NotificationChannel::bySlug(NotificationChannel::PUSH);
    $this->typeChannel = NotificationTypeChannel::query()->create([
        'notification_type_id' => $this->type->id,
        'notification_channel_id' => $this->channel->id,
    ]);
});

test('notification center relations are navigable both ways', function () {
    $admin = User::factory()->superAdmin()->create();
    $template = NotificationTemplate::query()->create([
        'notification_type_channel_id' => $this->typeChannel->id,
        'locale_id' => Locale::idFor(Locale::EN),
        'title' => 'Reminder: {% reminder.title %}',
        'body' => '<p>{% reminder.note %}</p>',
        'actions' => [['link_key' => 'reminder', 'label' => 'Open']],
        'updated_by_id' => $admin->id,
    ]);
    $preference = NotificationPreference::query()->create([
        'user_id' => $admin->id,
        'notification_type_id' => $this->type->id,
        'notification_channel_id' => $this->channel->id,
        'is_enabled' => false,
    ]);
    $notification = Notification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'reminder_due',
        'notification_type_id' => $this->type->id,
        'notifiable_type' => 'user',
        'notifiable_id' => $admin->id,
        'data' => ['title' => 'Reminder'],
    ]);

    expect($this->type->typeChannels->first()->is($this->typeChannel))->toBeTrue()
        ->and($this->channel->typeChannels->first()->is($this->typeChannel))->toBeTrue()
        ->and($this->typeChannel->type->is($this->type))->toBeTrue()
        ->and($this->typeChannel->channel->is($this->channel))->toBeTrue()
        ->and($this->typeChannel->templates->first()->is($template))->toBeTrue()
        ->and($template->typeChannel->is($this->typeChannel))->toBeTrue()
        ->and($template->locale->slug)->toBe(Locale::EN)
        ->and(Locale::bySlug(Locale::EN)->notificationTemplates->first()->is($template))->toBeTrue()
        ->and($template->updatedBy->is($admin))->toBeTrue()
        ->and($template->fresh()->actions)->toBe([['link_key' => 'reminder', 'label' => 'Open']])
        ->and($preference->user->is($admin))->toBeTrue()
        ->and($preference->type->is($this->type))->toBeTrue()
        ->and($preference->channel->is($this->channel))->toBeTrue()
        ->and($preference->is_enabled)->toBeFalse()
        ->and($admin->notificationPreferences->first()->is($preference))->toBeTrue()
        ->and($this->type->preferences->first()->is($preference))->toBeTrue()
        ->and($this->channel->preferences->first()->is($preference))->toBeTrue()
        ->and($notification->notificationType->is($this->type))->toBeTrue()
        ->and($this->type->notifications->first()->is($notification))->toBeTrue()
        ->and($admin->notifications->first())->toBeInstanceOf(Notification::class)
        ->and($notification->notifiable->is($admin))->toBeTrue();
});

test('saving notification settings flushes the cached configuration', function (string $change) {
    app(NotificationSettingsCache::class)->remember(fn () => ['cached' => true]);

    expect(Cache::has(NotificationSettingsCache::KEY))->toBeTrue();

    match ($change) {
        'type' => $this->type->update(['is_active' => false]),
        'channel' => $this->channel->update(['is_active' => false]),
        'type channel' => $this->typeChannel->update(['is_enabled_by_default' => false]),
        'template' => NotificationTemplate::query()->create([
            'notification_type_channel_id' => $this->typeChannel->id,
            'locale_id' => Locale::idFor(Locale::PT_BR),
            'title' => 'Lembrete',
            'body' => 'Corpo',
        ]),
        'deleted type channel' => $this->typeChannel->delete(),
    };

    expect(Cache::has(NotificationSettingsCache::KEY))->toBeFalse();
})->with(['type', 'channel', 'type channel', 'template', 'deleted type channel']);
