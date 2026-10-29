<?php

use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create();
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    $this->message = fn (string $id, string $remoteJid, array $overrides = []): array => [
        'key' => ['remoteJid' => $remoteJid, 'fromMe' => false, 'id' => $id],
        'pushName' => 'Ana',
        'message' => ['conversation' => 'Oi, tudo bem?'],
        'messageType' => 'conversation',
        'messageTimestamp' => CarbonImmutable::parse('2026-10-05 11:00', 'UTC')->getTimestamp(),
        ...$overrides,
    ];

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('a received message is kept in the chat of its contact', function () {
    ($this->postEvent)('messages.upsert', ($this->message)('MSG1', '5511911110000@s.whatsapp.net'))
        ->assertOk()
        ->assertJson(['status' => 'handled']);

    $target = WhatsappTarget::query()->where('jid', '5511911110000@s.whatsapp.net')->sole();
    expect($target->whatsapp_account_id)->toBe($this->account->id)
        ->and($target->name)->toBe('Ana');

    $message = WhatsappChatMessage::query()->sole();
    expect($message->whatsapp_target_id)->toBe($target->id)
        ->and($message->from_me)->toBeFalse()
        ->and($message->body)->toBe('Oi, tudo bem?')
        ->and($message->sent_at->toDateTimeString())->toBe('2026-10-05 11:00:00');

    // A retried webhook adds nothing.
    ($this->postEvent)('MESSAGES_UPSERT', ($this->message)('MSG1', '5511911110000@s.whatsapp.net'))->assertOk();
    expect(WhatsappChatMessage::query()->count())->toBe(1);
});

test('the history handed over on linking is kept, status updates and reactions left out', function () {
    $ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '5511911110000@s.whatsapp.net', 'name' => 'Ana Souza']);

    ($this->postEvent)('messages.set', [
        ($this->message)('H1', '5511911110000@s.whatsapp.net'),
        ($this->message)('H2', '5511911110000@s.whatsapp.net', [
            'key' => ['remoteJid' => '5511911110000@s.whatsapp.net', 'fromMe' => true, 'id' => 'H2'],
            'pushName' => 'Me',
            'message' => ['extendedTextMessage' => ['text' => 'tudo sim!']],
            'messageType' => 'extendedTextMessage',
        ]),
        ($this->message)('H3', 'status@broadcast'),
        ($this->message)('H4', '5511911110000@s.whatsapp.net', ['message' => ['reactionMessage' => ['text' => '👍']], 'messageType' => 'reactionMessage']),
        ($this->message)('H5', '5511922220000@s.whatsapp.net', ['message' => ['audioMessage' => ['seconds' => 4]], 'messageType' => 'audioMessage', 'pushName' => 'Bia']),
    ])->assertOk();

    expect(WhatsappChatMessage::query()->orderBy('external_id')->pluck('external_id')->all())->toBe(['H1', 'H2', 'H5'])
        ->and($ana->chatMessages()->where('external_id', 'H2')->sole()->body)->toBe('tudo sim!')
        ->and($ana->fresh()->name)->toBe('Ana Souza')
        ->and(WhatsappChatMessage::query()->where('external_id', 'H5')->sole()->body)->toBeNull()
        ->and(WhatsappTarget::query()->where('jid', 'status@broadcast')->exists())->toBeFalse();
});

test('a group message is kept in the group chat with who sent it', function () {
    WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '5511944440000@s.whatsapp.net', 'name' => 'Carlos']);
    $group = '120363000000000001@g.us';

    ($this->postEvent)('messages.upsert', [
        ($this->message)('G1', $group, [
            'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => 'G1', 'participant' => '81234567890123@lid', 'participantPn' => '5511933330000@s.whatsapp.net'],
            'pushName' => 'Bia',
        ]),
        ($this->message)('G2', $group, [
            'key' => ['remoteJid' => $group, 'fromMe' => false, 'id' => 'G2', 'participant' => '5511944440000@s.whatsapp.net'],
            'pushName' => null,
        ]),
        ($this->message)('G3', $group, [
            'key' => ['remoteJid' => $group, 'fromMe' => true, 'id' => 'G3'],
        ]),
    ])->assertOk();

    $target = WhatsappTarget::query()->where('jid', $group)->sole();
    $messages = $target->chatMessages()->orderBy('external_id')->get()->keyBy('external_id');

    expect($target->isGroup())->toBeTrue()
        ->and($target->name)->toBeNull()
        ->and($target->unread_count)->toBe(0)
        ->and($messages['G1']->only('sender_jid', 'sender_name'))->toBe(['sender_jid' => '5511933330000@s.whatsapp.net', 'sender_name' => 'Bia'])
        ->and($messages['G2']->senderLabel())->toBe('Carlos')
        ->and($messages['G3']->only('sender_jid', 'sender_name'))->toBe(['sender_jid' => null, 'sender_name' => null]);
});

test('a chat addressed by lid is kept under the phone jid when WhatsApp gives it', function () {
    ($this->postEvent)('messages.upsert', ($this->message)('L1', '81234567890123@lid', [
        'key' => ['remoteJid' => '81234567890123@lid', 'remoteJidAlt' => '5511933330000@s.whatsapp.net', 'fromMe' => false, 'id' => 'L1'],
    ]))->assertOk();

    ($this->postEvent)('messages.upsert', ($this->message)('L2', '89999999999999@lid'))->assertOk();

    expect(WhatsappTarget::query()->pluck('jid')->sort()->values()->all())
        ->toBe(['5511933330000@s.whatsapp.net', '89999999999999@lid']);

    // The contact keeps its lid, which presence updates may come with.
    expect(WhatsappTarget::query()->where('jid', '5511933330000@s.whatsapp.net')->value('lid'))->toBe('81234567890123@lid')
        ->and(WhatsappTarget::query()->where('jid', '89999999999999@lid')->value('lid'))->toBeNull();
});
