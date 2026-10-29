<?php

use App\Models\Locale;
use App\Models\Module;
use App\Models\Notification as StoredNotification;
use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\Channels\DatabaseChannel;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\NotificationRegistry;
use Database\Factories\WorkspaceFactory;
use Database\Seeders\LookupSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Notifications\AccountNotice;
use Tests\Fixtures\Notifications\ReminderNotice;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    config(['notifications.types' => [AccountNotice::class, ReminderNotice::class]]);

    app(NotificationProvisioner::class)->provisionAll();
});

/**
 * @return list<SendQueuedNotifications>
 */
function queuedNotificationJobs(): array
{
    return Queue::pushed(SendQueuedNotifications::class)->values()->all();
}

/**
 * @return list<string> "<user id>:<channel>" for every queued job
 */
function queuedDeliveries(): array
{
    return array_map(
        fn (SendQueuedNotifications $job): string => $job->notifiables->first()->getKey().':'.implode(',', $job->channels),
        queuedNotificationJobs(),
    );
}

/**
 * Runs a queued job as the worker would: unserialized, with its models read
 * again from the database.
 */
function runQueuedNotificationJob(SendQueuedNotifications $job): void
{
    unserialize(serialize($job))->handle(app(ChannelManager::class));
}

function typeChannelOf(string $typeKey, string $channel): NotificationTypeChannel
{
    return NotificationTypeChannel::query()
        ->where('notification_type_id', NotificationType::query()->where('slug', $typeKey)->value('id'))
        ->where('notification_channel_id', NotificationChannel::idFor($channel))
        ->sole();
}

test('each channel is queued as its own job on the notifications queue', function () {
    Queue::fake();

    $first = User::factory()->create();
    $second = User::factory()->create();

    app(NotificationCenter::class)->dispatch(new AccountNotice, [$first, $second, $first]);

    $jobs = queuedNotificationJobs();

    expect($jobs)->toHaveCount(4)
        ->and(queuedDeliveries())->toEqualCanonicalizing([
            $first->id.':'.DatabaseChannel::class,
            $first->id.':mail',
            $second->id.':'.DatabaseChannel::class,
            $second->id.':mail',
        ]);

    foreach ($jobs as $job) {
        expect($job->queue)->toBe(CenterNotification::QUEUE)
            ->and($job->notifiables)->toHaveCount(1)
            ->and($job->channels)->toHaveCount(1)
            ->and($job->tries)->toBe(3)
            ->and($job->backoff())->toBe([30, 120]);
    }
});

test('failing mail does not block database channel', function () {
    Queue::fake();

    $this->mock(MailChannel::class, fn ($mock) => $mock->shouldReceive('send')->andThrow(new RuntimeException('SMTP is down')));

    $user = User::factory()->create();

    app(NotificationCenter::class)->dispatch(new AccountNotice('Bills'), $user);

    $jobs = collect(queuedNotificationJobs());
    $mailJob = $jobs->first(fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']);
    $databaseJob = $jobs->first(fn (SendQueuedNotifications $job): bool => $job->channels === [DatabaseChannel::class]);

    expect(fn () => runQueuedNotificationJob($mailJob))->toThrow(RuntimeException::class, 'SMTP is down');

    runQueuedNotificationJob($databaseJob);

    expect($user->notifications()->sole()->data['title'])->toBe('Notice: Bills');
});

test('gate blocks inactive type', function () {
    $user = User::factory()->create();

    Queue::fake();
    app(NotificationCenter::class)->dispatch(new AccountNotice, $user);
    $alreadyQueued = queuedNotificationJobs();

    NotificationType::query()->where('slug', AccountNotice::key())->update(['is_active' => false]);
    app(NotificationRegistry::class)->flush();

    Queue::fake();
    app(NotificationCenter::class)->dispatch(new AccountNotice, $user);

    expect(queuedNotificationJobs())->toBeEmpty();

    // Checked again in the worker: what was queued before the switch is dropped.
    foreach ($alreadyQueued as $job) {
        runQueuedNotificationJob($job);
    }

    expect(StoredNotification::query()->count())->toBe(0);
});

test('gate blocks channel disabled on platform', function () {
    Queue::fake();

    $user = User::factory()->create();

    NotificationChannel::bySlug(NotificationChannel::MAIL)->update(['is_active' => false]);

    app(NotificationCenter::class)->dispatch(new AccountNotice, $user);

    expect(queuedDeliveries())->toBe([$user->id.':'.DatabaseChannel::class]);
});

test('gate respects user opt out', function () {
    Queue::fake();

    $optedOut = User::factory()->create();
    $untouched = User::factory()->create();
    $mail = typeChannelOf(AccountNotice::key(), NotificationChannel::MAIL);

    NotificationPreference::query()->create([
        'user_id' => $optedOut->id,
        'notification_type_id' => $mail->notification_type_id,
        'notification_channel_id' => $mail->notification_channel_id,
        'is_enabled' => false,
    ]);

    app(NotificationCenter::class)->dispatch(new AccountNotice, [$optedOut, $untouched]);

    expect(queuedDeliveries())->toEqualCanonicalizing([
        $optedOut->id.':'.DatabaseChannel::class,
        $untouched->id.':'.DatabaseChannel::class,
        $untouched->id.':mail',
    ]);
});

test('gate uses the type channel default when the user never chose', function () {
    Queue::fake();

    $user = User::factory()->create();

    typeChannelOf(AccountNotice::key(), NotificationChannel::MAIL)->update(['is_enabled_by_default' => false]);

    app(NotificationCenter::class)->dispatch(new AccountNotice, $user);

    expect(queuedDeliveries())->toBe([$user->id.':'.DatabaseChannel::class]);
});

test('gate falls back to english template', function () {
    $user = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);
    $database = typeChannelOf(AccountNotice::key(), NotificationChannel::DATABASE);

    NotificationTemplate::query()
        ->where('notification_type_channel_id', $database->id)
        ->where('locale_id', Locale::idFor(Locale::PT_BR))
        ->delete();

    app(NotificationCenter::class)->dispatch(new AccountNotice('Contas'), $user);

    expect($user->notifications()->sole()->data['title'])->toBe('Notice: Contas');
});

test('gate blocks a channel without a template in the language nor in english', function () {
    Queue::fake();

    $user = User::factory()->create(['locale_id' => Locale::idFor(Locale::PT_BR)]);

    NotificationTemplate::query()
        ->where('notification_type_channel_id', typeChannelOf(AccountNotice::key(), NotificationChannel::MAIL)->id)
        ->delete();

    app(NotificationCenter::class)->dispatch(new AccountNotice, $user);

    expect(queuedDeliveries())->toBe([$user->id.':'.DatabaseChannel::class]);
});

test('notification is written in the recipient language', function () {
    $user = User::factory()->create(['name' => 'Bia', 'locale_id' => Locale::idFor(Locale::PT_BR)]);

    app(NotificationCenter::class)->dispatch(new AccountNotice('Contas'), $user);

    $data = $user->notifications()->sole()->data;

    expect($data['title'])->toBe('Aviso: Contas')
        ->and($data['body'])->toBe('<p>Olá, Bia.</p>')
        ->and($data['actions'])->toBe([['label' => 'Abrir', 'url' => url('/')]]);
});

test('module types skip users without the module', function () {
    Queue::fake();

    $withModule = User::factory()->withModules([Module::REMINDERS])->create();
    $withoutModule = User::factory()->withModules([Module::SHOPPING])->create();
    $superAdmin = User::factory()->superAdmin()->create();

    app(NotificationCenter::class)->dispatch(new ReminderNotice, [$withModule, $withoutModule, $superAdmin]);

    expect(queuedDeliveries())->toEqualCanonicalizing([
        $withModule->id.':'.DatabaseChannel::class,
        $superAdmin->id.':'.DatabaseChannel::class,
    ]);

    // Losing the module before the worker runs drops the notification too.
    $withModule->membershipOf(WorkspaceFactory::household())->modules()->detach();

    foreach (queuedNotificationJobs() as $job) {
        runQueuedNotificationJob($job);
    }

    expect($withModule->notifications()->count())->toBe(0)
        ->and($superAdmin->notifications()->count())->toBe(1);
});

test('deactivated and deleted users get nothing', function () {
    Queue::fake();

    $deactivated = User::factory()->deactivated()->create();
    $deleted = User::factory()->create();
    $deleted->delete();

    app(NotificationCenter::class)->dispatch(new AccountNotice, [$deactivated, $deleted]);

    expect(queuedNotificationJobs())->toBeEmpty();
});
