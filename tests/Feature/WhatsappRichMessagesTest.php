<?php

use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\ChatInfo;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Support\Whatsapp\MessageFormat;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    Storage::fake((string) config('filesystems.private'));
    FakeEvolutionApi::install();
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()]);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Família', 'jid' => '120363000000000001@g.us', 'last_message_at' => now()]);

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
    $this->upsert = fn (string $id, string $type, array $content, array $key = []) => ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => $id, 'participant' => '5511922220000@s.whatsapp.net', ...$key],
        'pushName' => 'Bruno',
        'messageType' => $type,
        'message' => [$type => $content],
        'messageTimestamp' => now()->getTimestamp(),
    ]);
});

test('an answer keeps what it quotes, a mention shows the name, a forward is marked', function () {
    $original = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'MINE1', 'body' => 'Quem vem sábado?']);

    ($this->upsert)('IN1', 'extendedTextMessage', [
        'text' => 'Eu vou, @5511911110000 também',
        'contextInfo' => [
            'stanzaId' => 'MINE1',
            'participant' => '5511900000000@s.whatsapp.net',
            'quotedMessage' => ['conversation' => 'Quem vem sábado?'],
            'mentionedJid' => ['5511911110000@s.whatsapp.net'],
            'isForwarded' => true,
            'forwardingScore' => 1,
        ],
    ])->assertOk();
    // Quoting a message Jarvis never got still shows what it said.
    ($this->upsert)('IN2', 'extendedTextMessage', [
        'text' => 'Concordo',
        'contextInfo' => ['stanzaId' => 'GONE', 'participant' => '5511933330000@s.whatsapp.net', 'quotedMessage' => ['conversation' => 'Bem antigo']],
    ])->assertOk();

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->family))
        ->assertJsonPath('data.0.body', 'Concordo')
        ->assertJsonPath('data.0.quoted', ['friendly_id' => null, 'sender' => '+5511933330000', 'from_me' => false, 'type' => 'conversation', 'body' => 'Bem antigo'])
        ->assertJsonPath('data.1.body', 'Eu vou, @Ana também')
        ->assertJsonPath('data.1.quoted', ['friendly_id' => $original->friendly_id, 'sender' => 'You', 'from_me' => true, 'type' => 'conversation', 'body' => 'Quem vem sábado?'])
        ->assertJsonPath('data.1.mentions', [['jid' => $this->ana->jid, 'name' => 'Ana']])
        ->assertJsonPath('data.1.forwarded', true)
        ->assertJsonPath('data.0.forwarded', false);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->family->friendly_id])
        ->assertSeeHtml('data-chat-quoted="'.$original->friendly_id.'"')
        ->assertSeeHtml('Eu vou, <span class="font-medium text-chat-link" data-chat-mention>@Ana</span> também')
        ->assertSeeHtml('data-chat-forwarded');
});

test('a location, contacts and a poll are kept with what they carry', function () {
    ($this->upsert)('LOC1', 'locationMessage', ['degreesLatitude' => -23.5613, 'degreesLongitude' => -46.6565, 'name' => 'MASP', 'address' => 'Av. Paulista, 1578'])->assertOk();
    ($this->upsert)('VC1', 'contactMessage', ['displayName' => 'Carla', 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:Carla\nitem1.TEL;waid=5511944440000:+55 11 94444-0000\nEND:VCARD"])->assertOk();
    ($this->upsert)('POLL1', 'pollCreationMessageV3', ['name' => 'Pizza ou sushi?', 'options' => [['optionName' => 'Pizza'], ['optionName' => 'Sushi']], 'selectableOptionsCount' => 1])->assertOk();

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->family))
        ->assertJsonPath('data.0.poll', ['name' => 'Pizza ou sushi?', 'selectable_count' => 1, 'options' => [['name' => 'Pizza', 'votes' => 0, 'mine' => false], ['name' => 'Sushi', 'votes' => 0, 'mine' => false]]])
        ->assertJsonPath('data.1.body', 'Carla')
        ->assertJsonPath('data.1.contacts', [['name' => 'Carla', 'numbers' => ['+5511944440000']]])
        ->assertJsonPath('data.2.location.name', 'MASP')
        ->assertJsonPath('data.2.location.latitude', -23.5613);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->family->friendly_id])
        ->assertSeeHtml('data-chat-location')
        ->assertSeeHtml('data-chat-contact')
        ->assertSeeHtml('data-chat-poll');
});

test('messages are searched, starred and listed by kind in the side panel, and shown in the chat', function () {
    $old = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'O boleto vence dia 10', 'sent_at' => now()->subDays(30)]);
    WhatsappChatMessage::factory()->count(60)->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'outra coisa', 'sent_at' => now()->subDay()]);
    $link = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Olha https://jarvis.test/x', 'sent_at' => now()->subHour()]);
    $photo = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'message_type' => 'imageMessage', 'body' => null, 'media_mime' => 'image/jpeg']);

    Livewire::actingAs($this->owner)
        ->test(ChatInfo::class, ['chatId' => $this->ana->friendly_id])
        ->set('search', 'BOLETO dia')
        ->assertSeeHtml('data-chat-info-result="'.$old->friendly_id.'"')
        ->assertDontSeeHtml('data-chat-info-result="'.$link->friendly_id.'"')
        ->set('tab', ChatInfo::TAB_MEDIA)
        ->assertSeeHtml('data-chat-info-gallery-item="'.$photo->friendly_id.'"')
        ->set('galleryKind', WhatsappTarget::GALLERY_LINKS)
        ->assertSeeHtml('data-chat-info-gallery-item="'.$link->friendly_id.'"')
        ->call('show', $old->friendly_id)
        ->assertDispatched('whatsapp-show-message', messageId: $old->friendly_id);

    // The chat loads back as far as the message and scrolls to it.
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertDontSeeHtml('data-chat-message="'.$old->friendly_id.'"')
        ->call('showMessage', $old->friendly_id)
        ->assertSeeHtml('data-chat-message="'.$old->friendly_id.'"')
        ->assertDispatched('whatsapp-scroll-to', messageId: $old->friendly_id);

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.star', [$this->ana, $old->friendly_id]), ['starred' => true])
        ->assertOk()
        ->assertJsonPath('data.starred', true);

    Livewire::actingAs($this->owner)
        ->test(ChatInfo::class, ['chatId' => $this->ana->friendly_id, 'tab' => ChatInfo::TAB_STARRED])
        ->assertSeeHtml('data-chat-info-starred="'.$old->friendly_id.'"')
        ->call('unstar', $old->friendly_id)
        ->assertDontSeeHtml('data-chat-info-starred="'.$old->friendly_id.'"');

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.search', [$this->ana, 'q' => 'boleto']))
        ->assertOk()
        ->assertJsonPath('data.0.friendly_id', $old->friendly_id);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.gallery', [$this->ana, 'kind' => 'links']))
        ->assertJsonPath('data.0.friendly_id', $link->friendly_id);
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.star', [$this->ana, $link->friendly_id]), ['starred' => true]);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.starred', $this->ana))
        ->assertJsonPath('data.*.friendly_id', [$link->friendly_id]);

    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    app('auth')->forgetGuards();
    $this->withToken($stranger->createToken('Pixel')->plainTextToken)->getJson(route('api.whatsapp.conversations.search', [$this->ana, 'q' => 'boleto']))->assertForbidden();
});

test('a voice note recorded in the composer goes out on its own, keeping what was typed', function () {
    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-voice-record')
        ->set('text', 'Ainda escrevendo')
        ->set('voiceNote', UploadedFile::fake()->createWithContent('voice-note.ogg', 'OggS')->mimeType('audio/ogg'))
        ->call('sendVoice')
        ->assertHasNoErrors()
        ->assertSet('text', 'Ainda escrevendo')
        ->assertSet('voiceNote', null);

    expect(WhatsappDelivery::query()->sole())->media_mime->toBe('audio/ogg')->body->toBe('');
});

test('the chat list sums the last message up as WhatsApp does, and an edit envelope is no message', function () {
    ($this->upsert)('ENC1', 'secretEncryptedMessage', ['encPayload' => 'xyz', 'encIv' => 'abc'])->assertOk();
    expect(WhatsappChatMessage::query()->where('external_id', 'ENC1')->exists())->toBeFalse();

    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'message_type' => 'imageMessage', 'body' => null, 'sent_at' => now()]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'message_type' => 'viewOnceMessageV2', 'body' => null, 'sent_at' => now()->subMinute()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->family->friendly_id])
        ->assertSeeHtml('data-chat-item-preview-icon="photo"')
        ->assertSee('Photo')
        ->assertDontSee('[imageMessage]')
        ->assertSeeHtml('data-chat-unsupported="viewOnceMessageV2"')
        ->assertSee('You received a view once message. For more privacy, it can only be opened on the phone.');
});

test('a text shows bold, italic, strikethrough, code and links as WhatsApp formats them, escaped', function () {
    $html = (string) MessageFormat::toHtml("*oi* _tudo_ ~bem~ `x_y` https://exemplo.com/a_b <b>\n> citação\nfim @5511911110000", ['@5511911110000' => 'Ana']);

    expect($html)->toContain('<strong>oi</strong> <em>tudo</em> <s>bem</s>')
        ->toContain('<code class="rounded bg-fill px-1 font-mono text-[0.85em]">x_y</code>')
        ->toContain('<a href="https://exemplo.com/a_b" target="_blank" rel="noopener noreferrer" class="break-all text-chat-link hover:underline">https://exemplo.com/a_b</a>')
        ->toContain('&lt;b&gt;')
        ->toContain('<span class="block border-l-4 border-chat-meta pl-2 opacity-80">citação</span>fim')
        ->toContain('<span class="font-medium text-chat-link" data-chat-mention>@Ana</span>')
        ->and((string) MessageFormat::toHtml('2*3 e 4*5, snake_case_name'))->toBe('2*3 e 4*5, snake_case_name');
});
