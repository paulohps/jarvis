<?php

use App\Events\NotificationCreated;
use App\Events\WhatsappAccountStatusChanged;
use App\Events\WhatsappQrCodeUpdated;
use App\Jobs\SyncWhatsappTargets;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Services\Notifications\Types\WhatsappAccountDisconnected;
use App\Services\Whatsapp\EvolutionApiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->artisan('notifications:provision')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 12:00', 'UTC'));

    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();

    $this->postWebhook = fn (array $payload, ?string $secret = 'shared-secret') => $this->postJson(
        route('webhooks.evolution'),
        $payload,
        $secret === null ? [] : [EvolutionApiClient::WEBHOOK_SECRET_HEADER => $secret],
    );

    $this->connectionUpdate = fn (WhatsappAccount $account, string $state, array $data = []) => [
        'event' => 'connection.update',
        'instance' => $account->instance_name,
        'data' => ['instance' => $account->instance_name, 'state' => $state, 'statusReason' => 200, ...$data],
        'date_time' => '2026-10-03T09:00:00.000Z',
        'server_url' => 'http://evolution-api:8080',
        'apikey' => 'instance-token',
    ];
});

test('webhook without secret is rejected', function () {
    Queue::fake();

    $account = WhatsappAccount::factory()->connecting()->for($this->owner)->create();
    $payload = ($this->connectionUpdate)($account, 'open', ['wuid' => '5511999990000@s.whatsapp.net']);

    expect(route('webhooks.evolution', absolute: false))->toBe('/api/v1/webhooks/evolution');

    ($this->postWebhook)($payload, null)->assertUnauthorized()->assertJsonStructure(['message']);
    ($this->postWebhook)($payload, 'wrong-secret')->assertUnauthorized();

    // Without a configured secret nothing gets in, not even an empty header.
    config(['services.evolution.webhook_secret' => null]);
    ($this->postWebhook)($payload, '')->assertUnauthorized();

    expect($account->fresh()->status->slug)->toBe(WhatsappConnectionStatus::CONNECTING);
    Queue::assertNothingPushed();
});

test('open event marks account connected and queues sync', function () {
    Queue::fake();
    Event::fake([WhatsappAccountStatusChanged::class]);

    $account = WhatsappAccount::factory()->connecting()->for($this->owner)->create();

    ($this->postWebhook)(($this->connectionUpdate)($account, 'open', [
        'wuid' => '5511999990000@s.whatsapp.net',
        'profileName' => 'Paulo',
    ]))->assertOk()->assertExactJson(['status' => 'handled']);

    $account->refresh();

    expect($account->isOpen())->toBeTrue()
        ->and($account->connected_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00')
        ->and($account->phone_number)->toBe('+5511999990000')
        ->and($account->disconnected_at)->toBeNull();

    Queue::assertPushedOn('whatsapp', SyncWhatsappTargets::class, fn (SyncWhatsappTargets $job) => $job->account->is($account));
    Event::assertDispatched(WhatsappAccountStatusChanged::class, fn (WhatsappAccountStatusChanged $event) => $event->accountId === $account->friendly_id
        && $event->status === WhatsappConnectionStatus::OPEN);

    // The same state again changes nothing and syncs nothing more.
    $this->travel(5)->minutes();
    ($this->postWebhook)(($this->connectionUpdate)($account, 'open', ['wuid' => '5511999990000@s.whatsapp.net']))->assertOk();

    expect($account->fresh()->connected_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00');
    Queue::assertPushed(SyncWhatsappTargets::class, 1);

    // An unknown instance is acknowledged and ignored.
    ($this->postWebhook)([...($this->connectionUpdate)($account, 'open'), 'instance' => 'jarvis-unknown'])
        ->assertOk()
        ->assertExactJson(['status' => 'ignored']);

    Queue::assertPushed(SyncWhatsappTargets::class, 1);
});

test('closed event notifies the owner', function () {
    Notification::fake();

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $other = User::factory()->withModules([Module::WHATSAPP])->create();

    ($this->postWebhook)(($this->connectionUpdate)($account, 'close', ['statusReason' => 401]))->assertOk();

    $account->refresh();

    expect($account->status->slug)->toBe(WhatsappConnectionStatus::CLOSED)
        ->and($account->disconnected_at->toIso8601String())->toBe('2026-10-03T12:00:00+00:00');

    Notification::assertSentTo($this->owner, WhatsappAccountDisconnected::class, fn (WhatsappAccountDisconnected $notification) => $notification->account->is($account));
    Notification::assertNotSentTo($other, WhatsappAccountDisconnected::class);
    Notification::assertCount(1);

    // Closing again is not a new disconnection.
    ($this->postWebhook)(($this->connectionUpdate)($account, 'close'))->assertOk();
    Notification::assertCount(1);

    // A `close` while still pairing (expired QR, restart after the scan) is no disconnection.
    $pairing = WhatsappAccount::factory()->connecting()->for($this->owner)->create();

    ($this->postWebhook)(($this->connectionUpdate)($pairing, 'close', ['statusReason' => 515]))->assertOk();

    expect($pairing->fresh()->status->slug)->toBe(WhatsappConnectionStatus::CLOSED)
        ->and($pairing->fresh()->disconnected_at)->toBeNull();
    Notification::assertCount(1);
});

test('qr event broadcasts to the owner only', function () {
    Event::fake([WhatsappQrCodeUpdated::class]);

    $account = WhatsappAccount::factory()->connecting()->for($this->owner)->create();

    ($this->postWebhook)([
        'event' => 'qrcode.updated',
        'instance' => $account->instance_name,
        'data' => ['qrcode' => ['instance' => $account->instance_name, 'pairingCode' => 'WZYEH1YY', 'code' => '2@abc', 'base64' => 'data:image/png;base64,TkVX']],
    ])->assertOk()->assertExactJson(['status' => 'handled']);

    Event::assertDispatchedTimes(WhatsappQrCodeUpdated::class, 1);
    Event::assertDispatched(WhatsappQrCodeUpdated::class, function (WhatsappQrCodeUpdated $event) use ($account): bool {
        $channels = array_map(fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());

        return $channels === ['private-'.NotificationCreated::channelFor($this->owner->id)]
            && $event->broadcastWith() === ['account_id' => $account->friendly_id, 'qr_code' => 'data:image/png;base64,TkVX', 'pairing_code' => 'WZYEH1YY'];
    });

    // Only the owner may listen on that channel.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');

    $intruder = User::factory()->withModules([Module::WHATSAPP])->create();

    $this->actingAs($intruder)
        ->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.NotificationCreated::channelFor($this->owner->id)])
        ->assertForbidden();
});
