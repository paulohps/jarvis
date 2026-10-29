<?php

use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Livewire\Profile\ProfilePage;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationPreferenceMatrix;
use App\Services\Notifications\NotificationProvisioner;
use Database\Seeders\LookupSeeder;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\Notifications\AccountNotice;
use Tests\Fixtures\Notifications\ReminderNotice;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [AccountNotice::class, ReminderNotice::class]]);

    app(NotificationProvisioner::class)->provisionAll();

    $this->user = User::factory()->create();
});

function preferenceTypeChannel(string $typeKey, string $channel): NotificationTypeChannel
{
    return NotificationTypeChannel::query()
        ->where('notification_type_id', NotificationType::query()->where('slug', $typeKey)->value('id'))
        ->where('notification_channel_id', NotificationChannel::idFor($channel))
        ->sole();
}

/**
 * @return list<string> the channels of every queued notification job
 */
function queuedPreferenceChannels(): array
{
    return Queue::pushed(SendQueuedNotifications::class)
        ->flatMap(fn (SendQueuedNotifications $job): array => array_map(ChannelDrivers::slugFor(...), $job->channels))
        ->values()
        ->all();
}

test('preferences matrix hides inactive channels', function () {
    $matrix = app(NotificationPreferenceMatrix::class);

    // Without the reminders module, its types are not offered.
    expect($matrix->cellsFor($this->user))->toBe(['account_notice' => ['database', 'mail']])
        ->and($matrix->cellsFor(User::factory()->withModules([Module::REMINDERS])->create()))
        ->toBe(['account_notice' => ['database', 'mail'], 'reminder_notice' => ['database']]);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'notifications')
        ->assertSee('data-notification-cell="account_notice.database"', false)
        ->assertSee('data-notification-cell="account_notice.mail"', false)
        ->assertDontSee('reminder_notice');

    preferenceTypeChannel('account_notice', 'database')->update(['is_active' => false]);

    expect(app(NotificationPreferenceMatrix::class)->cellsFor($this->user))->toBe(['account_notice' => ['mail']]);

    NotificationChannel::bySlug('mail')->update(['is_active' => false]);

    expect(app(NotificationPreferenceMatrix::class)->cellsFor($this->user))->toBe([]);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'notifications')
        ->assertDontSee('data-notification-matrix', false)
        ->assertSee('No notifications to choose');
});

test('types hidden from the profile are not offered', function () {
    NotificationType::query()->where('slug', 'account_notice')->sole()->update(['is_visible_in_profile' => false]);

    expect(app(NotificationPreferenceMatrix::class)->cellsFor($this->user))->toBe([]);

    expect(fn () => app(UpdateNotificationPreferences::class)($this->user, [
        'preferences' => ['account_notice' => ['mail' => false]],
    ]))->toThrow(ValidationException::class);

    expect(NotificationPreference::query()->count())->toBe(0);
});

test('without a choice each cell shows the type channel default', function () {
    preferenceTypeChannel('account_notice', 'mail')->update(['is_enabled_by_default' => false]);

    $rows = app(NotificationPreferenceMatrix::class)->rowsFor($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('Account notice')
        ->and($rows[0]['channels'])->toBe([
            'database' => ['isEnabled' => true, 'isDefault' => true, 'isAvailable' => true],
            'mail' => ['isEnabled' => false, 'isDefault' => true, 'isAvailable' => true],
        ]);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->assertSet('preferences', ['account_notice' => ['database' => true, 'mail' => false]])
        ->set('tab', 'notifications')
        ->assertSee('data-notification-default="true"', false)
        ->set('preferences.account_notice.mail', true)
        ->assertHasNoErrors()
        ->assertDispatched('ts-ui:toast')
        ->assertSet('preferences.account_notice.mail', true);

    expect(app(NotificationPreferenceMatrix::class)->rowsFor($this->user)[0]['channels']['mail'])
        ->toBe(['isEnabled' => true, 'isDefault' => false, 'isAvailable' => true]);
});

test('opting out stops the next dispatch on that channel', function () {
    Queue::fake();

    app(NotificationCenter::class)->dispatch(new AccountNotice, $this->user);

    expect(queuedPreferenceChannels())->toEqualCanonicalizing(['database', 'mail']);

    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'notifications')
        ->set('preferences.account_notice.mail', false)
        ->assertHasNoErrors();

    expect(NotificationPreference::query()->sole())
        ->user_id->toBe($this->user->id)
        ->notification_channel_id->toBe(NotificationChannel::idFor('mail'))
        ->is_enabled->toBeFalse();

    Queue::fake();

    app(NotificationCenter::class)->dispatch(new AccountNotice, $this->user);

    expect(queuedPreferenceChannels())->toBe(['database']);

    // Someone else keeps the default.
    Queue::fake();

    app(NotificationCenter::class)->dispatch(new AccountNotice, User::factory()->create());

    expect(queuedPreferenceChannels())->toEqualCanonicalizing(['database', 'mail']);
});

test('whatsapp channel is unavailable without account and phone', function () {
    $account = WhatsappAccount::factory()->for($this->user)->create();

    expect(NotificationPreferenceMatrix::isChannelAvailableFor($this->user, 'whatsapp'))->toBeFalse()
        ->and(NotificationPreferenceMatrix::isChannelAvailableFor($this->user, 'mail'))->toBeTrue();

    $this->user->update(['whatsapp_notification_account_id' => $account->id]);

    expect(NotificationPreferenceMatrix::isChannelAvailableFor($this->user, 'whatsapp'))->toBeFalse();

    $this->user->update(['whatsapp_notification_account_id' => null, 'whatsapp_notification_phone' => '+5511999990000']);

    expect(NotificationPreferenceMatrix::isChannelAvailableFor($this->user, 'whatsapp'))->toBeFalse();

    try {
        app(UpdateNotificationPreferences::class)($this->user, [
            'preferences' => ['account_notice' => ['whatsapp' => true]],
        ]);
        $this->fail('The whatsapp channel was accepted without an account.');
    } catch (ValidationException $exception) {
        expect($exception->status)->toBe(422)
            ->and($exception->errors())->toBe([
                'preferences.account_notice.whatsapp' => ['Choose the WhatsApp account and number for notifications first.'],
            ]);
    }

    $this->user->update(['whatsapp_notification_account_id' => $account->id]);

    expect(NotificationPreferenceMatrix::isChannelAvailableFor($this->user, 'whatsapp'))->toBeTrue()
        ->and(NotificationPreference::query()->count())->toBe(0);
});

test('the matrix refuses cells it does not offer', function (array $preferences) {
    expect(fn () => app(UpdateNotificationPreferences::class)($this->user, ['preferences' => $preferences]))
        ->toThrow(ValidationException::class);

    expect(NotificationPreference::query()->count())->toBe(0);
})->with([
    'unknown type' => [['nope' => ['mail' => false]]],
    'channel the type lacks' => [['account_notice' => ['push' => false]]],
    'module the user lacks' => [['reminder_notice' => ['database' => false]]],
    'not a boolean' => [['account_notice' => ['mail' => 'maybe']]],
    'nothing' => [[]],
]);

test('a refused switch goes back to the stored value', function () {
    Livewire::actingAs($this->user)
        ->test(ProfilePage::class)
        ->set('tab', 'notifications')
        ->set('preferences.account_notice.push', true)
        ->assertHasErrors(['preferences.account_notice.push'])
        ->assertSet('preferences', ['account_notice' => ['database' => true, 'mail' => true]]);
});
