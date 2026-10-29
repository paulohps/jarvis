<?php

use App\Livewire\Whatsapp\Chat;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = '5511911110000@s.whatsapp.net';
    $this->chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $this->ana, 'name' => null, 'last_message_at' => now()]);

    $this->sent = fn (string $id, string $remoteJid, array $extra = []): array => [
        'key' => ['remoteJid' => $remoteJid, 'fromMe' => true, 'id' => $id, ...($extra['key'] ?? [])],
        'messageType' => 'conversation',
        'message' => ['conversation' => "Mensagem {$id}"],
        'messageTimestamp' => now()->subMinutes(5)->getTimestamp(),
        ...array_diff_key($extra, ['key' => true]),
    ];

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
});

test('a sent message shows its ticks, which only go up as WhatsApp reports them', function () {
    ($this->postEvent)('messages.upsert', ($this->sent)('OUT1', $this->ana, ['status' => 'SERVER_ACK']))->assertOk();
    $message = WhatsappChatMessage::query()->sole();
    expect($message->receipt)->toBe(WhatsappChatMessage::RECEIPT_SENT);

    ($this->postEvent)('messages.update', ['keyId' => 'OUT1', 'remoteJid' => '81234567890123@lid', 'fromMe' => true, 'status' => 'READ'])->assertOk();
    ($this->postEvent)('MESSAGES_UPDATE', ['keyId' => 'OUT1', 'remoteJid' => $this->ana, 'fromMe' => true, 'status' => 'DELIVERY_ACK'])->assertOk();
    ($this->postEvent)('messages.update', ['keyId' => 'GHOST', 'remoteJid' => $this->ana, 'fromMe' => true, 'status' => 'READ'])->assertOk();

    expect($message->fresh()->receipt)->toBe(WhatsappChatMessage::RECEIPT_READ);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->assertSeeHtml('data-chat-receipt="'.WhatsappChatMessage::RECEIPT_READ.'"');
});

test('another account\'s message with the same id keeps its ticks', function () {
    $other = WhatsappAccount::factory()->open()->create();
    $otherChat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $other->id, 'jid' => $this->ana]);
    $foreign = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $otherChat->id, 'external_id' => 'OUT1', 'from_me' => true, 'receipt' => WhatsappChatMessage::RECEIPT_SENT]);

    ($this->postEvent)('messages.update', ['keyId' => 'OUT1', 'fromMe' => true, 'status' => 'READ'])->assertOk();

    expect($foreign->fresh()->receipt)->toBe(WhatsappChatMessage::RECEIPT_SENT);
});

test('syncing a chat brings its name, picture, missing messages and ticks from WhatsApp', function () {
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->chat->id, 'external_id' => 'OUT1', 'from_me' => true, 'receipt' => WhatsappChatMessage::RECEIPT_SENT]);

    $records = fn (array $where): array => match ($where['remoteJid'] ?? $where['remoteJidAlt'] ?? null) {
        $this->ana => isset($where['remoteJid'])
            ? [($this->sent)('OUT1', $this->ana, ['MessageUpdate' => [['status' => 'READ'], ['status' => 'DELIVERY_ACK']]]), ($this->sent)('OUT2', $this->ana)]
            : [($this->sent)('OUT3', '81234567890123@lid', ['key' => ['remoteJidAlt' => $this->ana], 'MessageUpdate' => [['status' => 'DELIVERY_ACK']]])],
        default => [],
    };
    FakeEvolutionApi::install(['findMessages' => fn (Request $request) => Http::response(['messages' => [
        'total' => 1, 'pages' => 1, 'currentPage' => 1, 'records' => $records($request['where']['key'] ?? []),
    ]])]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->call('syncChat')
        ->assertSet('error', null)
        ->assertSet('notice', 'Chat in sync with WhatsApp: 2 messages brought in.')
        ->assertSeeHtml('data-chat-picture');

    $receipts = WhatsappChatMessage::query()->orderBy('external_id')->pluck('receipt', 'external_id')->all();
    expect($this->chat->fresh()->only('name', 'picture_url'))->toBe(['name' => 'Ana', 'picture_url' => FakeEvolutionApi::PICTURE_URL])
        ->and($receipts)->toBe(['OUT1' => WhatsappChatMessage::RECEIPT_READ, 'OUT2' => null, 'OUT3' => WhatsappChatMessage::RECEIPT_DELIVERED]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/webhook/set/') && in_array('MESSAGES_UPDATE', $request['webhook']['events'], true));
});

test('syncing a group takes its subject and picture from the group', function () {
    $group = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '120363000000000001@g.us', 'name' => null, 'last_message_at' => now()]);
    FakeEvolutionApi::install(['groupInfo' => ['id' => $group->jid, 'subject' => 'Família', 'pictureUrl' => 'https://pps.whatsapp.net/family.jpg']]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $group->friendly_id])
        ->call('syncChat')
        ->assertSet('notice', 'Chat in sync with WhatsApp.');

    expect($group->fresh()->only('name', 'picture_url'))->toBe(['name' => 'Família', 'picture_url' => 'https://pps.whatsapp.net/family.jpg']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/chat/fetchProfilePictureUrl/'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/findMessages/') && ! isset($request['where']['key']['remoteJidAlt']));
});

test('a group shows who sent each run of messages with their picture, asked of WhatsApp once a day', function () {
    $group = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '120363000000000001@g.us', 'last_message_at' => now()]);
    $bia = '5511933330000@s.whatsapp.net';
    $carlos = '5511944440000@s.whatsapp.net';
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => $carlos, 'name' => 'Carlos', 'picture_url' => 'https://pps.whatsapp.net/carlos.jpg']);

    foreach ([[$bia, 'Bia'], [$bia, 'Bia'], [$carlos, null]] as $index => [$jid, $name]) {
        WhatsappChatMessage::factory()->create([
            'whatsapp_target_id' => $group->id, 'from_me' => false, 'sender_jid' => $jid, 'sender_name' => $name,
            'external_id' => "G{$index}", 'sent_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $component = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $group->friendly_id])
        ->assertSeeHtml('$wire.loadSenderPictures()')
        ->assertSeeHtml('src="https://pps.whatsapp.net/carlos.jpg"');

    // One avatar and one name per run of messages from the same sender.
    expect(substr_count($component->html(), 'data-chat-sender-avatar="'.$bia.'"'))->toBe(1)
        ->and(substr_count($component->html(), 'data-chat-sender>'))->toBe(2);

    $component->call('loadSenderPictures')
        ->assertSeeHtml('src="'.e(FakeEvolutionApi::PICTURE_URL).'"')
        ->assertDontSeeHtml('$wire.loadSenderPictures()')
        ->call('loadSenderPictures');

    // Opening the chat also asks for its presence (WatchWhatsappChatPresence).
    expect(Http::recorded(fn (Request $request): bool => ! str_contains($request->url(), '/chat/sendPresence/')))->toHaveCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/fetchProfilePictureUrl/') && $request['number'] === $bia);
});

test('a message deleted for everyone stays, marked, by the webhook or the sync', function () {
    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->ana, 'fromMe' => false, 'id' => 'IN1'],
        'pushName' => 'Ana', 'messageType' => 'conversation', 'message' => ['conversation' => 'Segredo'],
        'messageTimestamp' => now()->subMinutes(3)->getTimestamp(),
    ])->assertOk();

    ($this->postEvent)('messages.delete', ['remoteJid' => $this->ana, 'fromMe' => false, 'id' => 'IN1', 'status' => 'DELETED'])->assertOk();

    $message = WhatsappChatMessage::query()->where('external_id', 'IN1')->sole();
    expect($message->isRevoked())->toBeTrue()->and($message->body)->toBe('Segredo');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->assertSeeHtml('data-chat-revoked')
        ->assertSee('Segredo');

    // A revoke in the history, and the DELETED status the Evolution API keeps, mark it too.
    ($this->postEvent)('messages.set', [
        ($this->sent)('OUT9', $this->ana),
        ['key' => ['remoteJid' => $this->ana, 'fromMe' => true, 'id' => 'REV1'], 'messageType' => 'protocolMessage',
            'message' => ['protocolMessage' => ['type' => 'REVOKE', 'key' => ['remoteJid' => $this->ana, 'fromMe' => true, 'id' => 'OUT9']]],
            'messageTimestamp' => now()->getTimestamp()],
        ($this->sent)('OUT10', $this->ana, ['MessageUpdate' => [['status' => 'DELIVERY_ACK'], ['status' => 'DELETED']]]),
    ])->assertOk();

    expect(WhatsappChatMessage::query()->whereNotNull('revoked_at')->orderBy('external_id')->pluck('external_id')->all())->toBe(['IN1', 'OUT10', 'OUT9'])
        ->and(WhatsappChatMessage::query()->where('external_id', 'REV1')->exists())->toBeFalse();
});

test('a voice note keeps its length and waveform, and a video its preview, to show before loading', function () {
    ($this->postEvent)('messages.set', [
        ['key' => ['remoteJid' => $this->ana, 'fromMe' => false, 'id' => 'PTT1'], 'messageType' => 'audioMessage',
            'message' => ['audioMessage' => ['ptt' => true, 'seconds' => 7, 'mimetype' => 'audio/ogg; codecs=opus', 'waveform' => base64_encode(pack('C*', ...range(0, 63)))]],
            'messageTimestamp' => now()->subMinutes(2)->getTimestamp()],
        ['key' => ['remoteJid' => $this->ana, 'fromMe' => false, 'id' => 'VID1'], 'messageType' => 'videoMessage',
            'message' => ['videoMessage' => ['seconds' => 75, 'mimetype' => 'video/mp4', 'jpegThumbnail' => FakeEvolutionApi::MEDIA_BASE64]],
            'messageTimestamp' => now()->subMinute()->getTimestamp()],
    ])->assertOk();

    $voice = WhatsappChatMessage::query()->where('external_id', 'PTT1')->sole();
    $bars = $voice->waveformBars(16);
    expect($voice->media_seconds)->toBe(7)
        ->and($bars)->toHaveCount(16)
        ->and($bars[15])->toBe(100)
        ->and($bars[0])->toBeLessThan($bars[8]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->assertSeeHtml('data-chat-waveform')
        ->assertSee('0:07')
        ->assertSee('1:15')
        ->assertSeeHtml('poster="data:image/jpeg;base64,'.FakeEvolutionApi::MEDIA_BASE64.'"')
        ->assertSeeHtml('data-chat-load="'.$voice->friendly_id.'"');
});

test('a nickname shows in place of the WhatsApp name everywhere, and syncs leave it alone', function () {
    $this->chat->update(['name' => 'Ana Souza']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->call('editNickname')
        ->assertSet('showNickname', true)
        ->set('nickname', '  Mãe  ')
        ->call('saveNickname')
        ->assertHasNoErrors()
        ->assertSee('Mãe')
        ->assertSee('“Ana Souza” on WhatsApp')
        ->set('search', 'mãe')
        ->assertSeeInIsland('data-chat-item="'.$this->chat->friendly_id.'"')
        ->call('syncChat');

    expect($this->chat->fresh()->only('name', 'nickname'))->toBe(['name' => 'Ana', 'nickname' => 'Mãe'])
        ->and($this->chat->fresh()->displayName())->toBe('Mãe');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->chat->friendly_id])
        ->call('editNickname')
        ->assertSet('nickname', 'Mãe')
        ->set('nickname', '')
        ->call('saveNickname');

    expect($this->chat->fresh()->displayName())->toBe('Ana');

    Livewire::actingAs(User::factory()->withModules([Module::WHATSAPP])->create())
        ->test(Chat::class)
        ->call('openChat', $this->chat->friendly_id)
        ->assertSet('chatId', null);
});
