<?php

use App\Models\Device;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Services\Notifications\NotificationProvisioner;
use App\Services\Notifications\Types\WhatsappMessageReceived;
use App\Services\Whatsapp\EvolutionApiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Push\FakeFcm;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    config(['services.evolution.webhook_secret' => 'shared-secret', 'notifications.types' => [WhatsappMessageReceived::class]]);
    app(NotificationProvisioner::class)->provisionAll();
    FakeFcm::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    Device::factory()->for($this->owner)->create(['push_token' => 'phone-token']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);

    $this->post = fn (string $event, string $remoteJid, array $key = [], array $message = ['conversation' => 'Vamos amanhã?']) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => [
            'key' => ['remoteJid' => $remoteJid, 'fromMe' => false, 'id' => 'M'.random_int(1, PHP_INT_MAX), ...$key],
            'pushName' => 'Carla',
            'message' => $message,
            'messageType' => array_key_first($message),
            'messageTimestamp' => now()->subMinute()->getTimestamp(),
        ]],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    )->assertOk();
});

/**
 * @return list<array<string, mixed>>
 */
function whatsappPushes(): array
{
    return Http::recorded(fn (Request $request): bool => FakeFcm::isSend($request))
        ->map(fn (array $pair): array => $pair[0]['message'])
        ->values()
        ->all();
}

test('a message the contact sends is pushed to the owner\'s phone, grouped by chat, opening the chat', function () {
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net');

    $push = whatsappPushes()[0];

    expect(whatsappPushes())->toHaveCount(1)
        ->and($push['token'])->toBe('phone-token')
        ->and($push['notification'])->toBe(['title' => 'Ana', 'body' => 'Vamos amanhã?'])
        ->and($push['data']['deep_link'])->toBe(route('whatsapp.chat', ['account' => $this->account->friendly_id, 'chat' => $this->ana->friendly_id]))
        ->and($push['data']['thread'])->toBe('whatsapp:'.$this->ana->friendly_id)
        ->and($push['android']['notification'])->toBe(['tag' => 'whatsapp:'.$this->ana->friendly_id, 'channel_id' => WhatsappMessageReceived::ANDROID_CHANNEL])
        ->and($push['apns']['payload']['aps']['thread-id'])->toBe('whatsapp:'.$this->ana->friendly_id);
});

test('in a group the sender comes first, and a media says what it is', function () {
    WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Family', 'jid' => '120363000000000001@g.us']);

    ($this->post)('messages.upsert', '120363000000000001@g.us', ['participant' => '5511955550000@s.whatsapp.net']);
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net', [], ['imageMessage' => ['mimetype' => 'image/jpeg']]);

    expect(array_column(whatsappPushes(), 'notification'))->toBe([
        ['title' => 'Family', 'body' => 'Carla: Vamos amanhã?'],
        ['title' => 'Ana', 'body' => '📷 Photo'],
    ]);
});

test('no push for what the number sends, the history, or a chat with notifications off', function () {
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net', ['fromMe' => true]);
    ($this->post)('messages.set', '5511911110000@s.whatsapp.net');

    $this->ana->update(['notifies_messages' => false]);
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net');

    expect(whatsappPushes())->toBe([]);

    // A chat turned on notifies while its number does not.
    $this->account->update(['notifies_messages' => false]);
    $this->ana->update(['notifies_messages' => true]);
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net');

    $this->ana->update(['notifies_messages' => null]);
    ($this->post)('messages.upsert', '5511911110000@s.whatsapp.net');

    expect(whatsappPushes())->toHaveCount(1);
});
