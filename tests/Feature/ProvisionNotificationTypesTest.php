<?php

use App\Models\Locale;
use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LookupSeeder;
use Tests\Fixtures\Notifications\AccountNotice;
use Tests\Fixtures\Notifications\ReminderNotice;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [AccountNotice::class, ReminderNotice::class]]);
});

/**
 * @return array<string, array<string, array{title: string, body: string}>> channel => locale => text
 */
function provisionedTemplatesOf(string $typeKey): array
{
    $templates = [];

    NotificationType::query()
        ->where('slug', $typeKey)
        ->sole()
        ->typeChannels()
        ->with(['channel', 'templates.locale'])
        ->get()
        ->each(function (NotificationTypeChannel $typeChannel) use (&$templates): void {
            foreach ($typeChannel->templates as $template) {
                $templates[$typeChannel->channel->slug][$template->locale->slug] = [
                    'title' => $template->title,
                    'body' => $template->body,
                ];
            }
        });

    return $templates;
}

function provisionedTemplate(string $typeKey, string $channel, string $locale): NotificationTemplate
{
    return NotificationTemplate::query()
        ->whereRelation('typeChannel.type', 'slug', $typeKey)
        ->whereRelation('typeChannel', 'notification_channel_id', NotificationChannel::idFor($channel))
        ->where('locale_id', Locale::idFor($locale))
        ->sole();
}

test('provisioning creates types channels and templates in both locales', function () {
    $this->artisan('notifications:provision')
        ->expectsOutputToContain('Provisioned: account_notice, reminder_notice.')
        ->assertSuccessful();

    $account = NotificationType::query()->where('slug', AccountNotice::key())->sole();

    expect($account->is_active)->toBeTrue()
        ->and($account->is_visible_in_profile)->toBeTrue()
        ->and(provisionedTemplatesOf(AccountNotice::key()))->toBe([
            'database' => [
                'en' => ['title' => 'Notice: {% notice.subject %}', 'body' => '<p>Hi, {% user.name %}.</p>'],
                'pt_BR' => ['title' => 'Aviso: {% notice.subject %}', 'body' => '<p>Olá, {% user.name %}.</p>'],
            ],
            'mail' => [
                'en' => ['title' => 'Notice: {% notice.subject %}', 'body' => '<p>Hi, {% user.name %}.</p>'],
                'pt_BR' => ['title' => 'Aviso: {% notice.subject %}', 'body' => '<p>Olá, {% user.name %}.</p>'],
            ],
        ])
        ->and(array_keys(provisionedTemplatesOf(ReminderNotice::key())))->toBe(['database'])
        ->and(provisionedTemplate(AccountNotice::key(), 'database', 'en')->actions)->toBe([['link_key' => 'home', 'label' => 'Open']]);

    // Idempotent: a second run writes nothing.
    $this->artisan('notifications:provision')
        ->expectsOutputToContain('Every notification type is already provisioned.')
        ->assertSuccessful();

    expect(NotificationType::query()->count())->toBe(2)
        ->and(NotificationTypeChannel::query()->count())->toBe(3)
        ->and(NotificationTemplate::query()->count())->toBe(6);
});

test('provisioning never overwrites edited templates', function () {
    $this->artisan('notifications:provision')->assertSuccessful();

    $admin = User::factory()->superAdmin()->create();
    $edited = provisionedTemplate(AccountNotice::key(), 'mail', 'en');
    $edited->update(['title' => 'Edited {% notice.subject %}', 'body' => '<p>Edited</p>', 'actions' => [], 'updated_by_id' => $admin->id]);

    // A template nobody edited follows the defaults again.
    $untouched = provisionedTemplate(AccountNotice::key(), 'database', 'en');
    $untouched->update(['title' => 'Stale default']);

    // Switches the super admin turned off stay off.
    NotificationType::query()->where('slug', AccountNotice::key())->update(['is_active' => false]);

    $this->artisan('notifications:provision')->assertSuccessful();

    expect($edited->fresh()->title)->toBe('Edited {% notice.subject %}')
        ->and($edited->fresh()->body)->toBe('<p>Edited</p>')
        ->and($edited->fresh()->actions)->toBe([])
        ->and($edited->fresh()->updated_by_id)->toBe($admin->id)
        ->and($untouched->fresh()->title)->toBe('Notice: {% notice.subject %}')
        ->and(NotificationType::query()->where('slug', AccountNotice::key())->value('is_active'))->toBeFalse();
});

test('the database seeder provisions the notification types', function () {
    $this->seed(DatabaseSeeder::class);

    expect(NotificationType::query()->pluck('slug')->sort()->values()->all())
        ->toBe([AccountNotice::key(), ReminderNotice::key()]);
});
