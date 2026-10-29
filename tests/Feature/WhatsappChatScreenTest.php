<?php

use App\Actions\Whatsapp\RecordWhatsappChatMessages;
use App\Events\NotificationCreated;
use App\Events\WhatsappChatUpdated;
use App\Livewire\Whatsapp\Chat;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);

    $this->wa = fn (string $id, string $remoteJid, bool $fromMe = false, string $at = '2026-10-06 11:00'): array => [
        'key' => ['remoteJid' => $remoteJid, 'fromMe' => $fromMe, 'id' => $id],
        'pushName' => 'Ana',
        'messageType' => 'conversation',
        'message' => ['conversation' => "message {$id}"],
        'messageTimestamp' => CarbonImmutable::parse($at, 'UTC')->getTimestamp(),
    ];
});

test('live messages count as unread until the number answers, a history never does', function () {
    Event::fake([WhatsappChatUpdated::class]);
    $record = app(RecordWhatsappChatMessages::class);
    $ana = '5511911110000@s.whatsapp.net';

    $record($this->account, [($this->wa)('H1', $ana, at: '2026-10-01 09:00')]);

    $target = WhatsappTarget::query()->where('jid', $ana)->sole();
    expect($target->unread_count)->toBe(0)
        ->and($target->last_message_at->toDateTimeString())->toBe('2026-10-01 09:00:00');
    Event::assertNotDispatched(WhatsappChatUpdated::class);

    $record($this->account, [($this->wa)('L1', $ana), ($this->wa)('L2', $ana, at: '2026-10-06 11:05')], live: true);
    // A retried webhook counts nothing twice.
    $record($this->account, [($this->wa)('L2', $ana, at: '2026-10-06 11:05')], live: true);

    expect($target->fresh()->unread_count)->toBe(2)
        ->and($target->fresh()->last_message_at->toDateTimeString())->toBe('2026-10-06 11:05:00');
    Event::assertDispatched(WhatsappChatUpdated::class, fn (WhatsappChatUpdated $event): bool => $event->userId === $this->owner->id
        && $event->chatId === $target->friendly_id
        && $event->broadcastOn()[0]->name === 'private-'.NotificationCreated::channelFor($this->owner->id));

    // An older message from the history keeps the newest time.
    $record($this->account, [($this->wa)('H2', $ana, at: '2026-09-01 09:00')]);
    expect($target->fresh()->last_message_at->toDateTimeString())->toBe('2026-10-06 11:05:00');

    // Answered from the phone.
    $record($this->account, [($this->wa)('M1', $ana, fromMe: true, at: '2026-10-06 11:10')], live: true);
    expect($target->fresh()->unread_count)->toBe(0);
});

test('the screen lists the chats newest first, without the panel, and opening one marks it read', function () {
    $ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'last_message_at' => now()->subHour(), 'unread_count' => 3]);
    $bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'last_message_at' => now()->subMinute()]);
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Never wrote']);
    WhatsappChatMessage::factory()->for($ana, 'target')->create(['body' => 'Oi Jarvis', 'sent_at' => now()->subHour()]);

    $this->actingAs($this->owner)
        ->get(route('whatsapp.chat'))
        ->assertOk()
        ->assertSeeInOrder(['Bruno', 'Ana'])
        ->assertDontSee('Never wrote')
        ->assertDontSee(__('Main navigation'))
        ->assertSee('data-chat-unread="'.$ana->friendly_id.'"', false);

    // The list renders again only when opening the chat read it.
    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('openChat', $ana->friendly_id)
        ->assertSet('chatId', $ana->friendly_id)
        ->assertSeeHtml('data-chat-title')
        ->assertSee('Oi Jarvis')
        ->assertSeeInIsland('data-chat-item="'.$ana->friendly_id.'"')
        ->assertDontSeeInIsland('data-chat-unread="'.$ana->friendly_id.'"');

    expect($ana->fresh()->unread_count)->toBe(0)
        ->and($screen->call('openChat', $bruno->friendly_id)->effects)->not->toHaveKey('islandFragments');

    // A message coming in (the socket) renders the list.
    $screen->call('chatUpdated', ['chat_id' => $ana->friendly_id])->assertSeeInIsland('data-chat-item="'.$bruno->friendly_id.'"');
});

test('nobody opens the chats of somebody else\'s number', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'last_message_at' => now()]);

    Livewire::actingAs($stranger)
        ->test(Chat::class, ['accountId' => $this->account->friendly_id, 'chatId' => $chat->friendly_id])
        ->assertDontSee('Ana')
        ->assertSet('chatId', $chat->friendly_id)
        ->assertDontSeeHtml('data-chat-title');

    $this->actingAs(User::factory()->create())->get(route('whatsapp.chat'))->assertForbidden();
});

test('older messages load on demand', function () {
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'last_message_at' => now()]);
    WhatsappChatMessage::factory()->for($chat, 'target')->count(Chat::MESSAGES_PER_PAGE + 5)
        ->sequence(fn ($sequence) => ['sent_at' => now()->subMinutes(100 - $sequence->index), 'body' => "line {$sequence->index}"])
        ->create();

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->assertSeeHtml('data-chat-load-older')
        ->assertDontSee('line 4<')
        ->assertSee('line 5')
        ->call('loadOlder')
        ->assertSee('line 0')
        ->assertDontSeeHtml('data-chat-load-older');
});

test('what is typed goes out through the WhatsApp service and shows in the chat once sent', function () {
    FakeEvolutionApi::install();
    $chat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()->subDay(), 'unread_count' => 1]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $chat->friendly_id])
        ->set('text', '  ')
        ->call('send')
        ->assertHasErrors('text')
        ->set('text', ' Tudo certo! ')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('text', '');

    $delivery = WhatsappDelivery::query()->sole();
    expect($delivery->whatsapp_delivery_source_id)->toBe(WhatsappDeliverySource::idFor(WhatsappDeliverySource::CHAT))
        ->and($delivery->delivery_status_id)->toBe(DeliveryStatus::idFor(DeliveryStatus::SENT))
        ->and($delivery->body)->toBe('Tudo certo!');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendText/') && $request['text'] === 'Tudo certo!');

    $sent = WhatsappChatMessage::query()->sole();
    expect($sent->from_me)->toBeTrue()
        ->and($sent->external_id)->toBe(FakeEvolutionApi::MESSAGE_ID)
        ->and($sent->body)->toBe('Tudo certo!')
        ->and($chat->fresh()->unread_count)->toBe(0)
        ->and($chat->fresh()->last_message_at->equalTo(now()))->toBeTrue();
});

test('a new chat opens with a number typed in international format', function () {
    $known = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '5511922220000@s.whatsapp.net', 'name' => 'Bruno']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->set('showNewChat', true)
        ->set('number', '11 99999-0000')
        ->call('startChat')
        ->assertHasErrors('number')
        ->set('number', '+55 (11) 92222-0000')
        ->call('startChat')
        ->assertHasNoErrors()
        ->assertSet('chatId', $known->friendly_id)
        ->assertSet('showNewChat', false)
        ->set('showNewChat', true)
        ->set('number', '+5511933330000')
        ->call('startChat')
        ->assertSeeHtml('data-chat-title');

    expect(WhatsappTarget::query()->where('jid', '5511933330000@s.whatsapp.net')->sole()->is_synced)->toBeFalse();
});

test('groups show on the screen with who sent each message, and stay off the reply keyboard', function () {
    FakeEvolutionApi::install();
    $group = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'jid' => '120363000000000001@g.us', 'name' => 'Família', 'last_message_at' => now()]);
    WhatsappChatMessage::factory()->for($group, 'target')->create(['body' => 'Almoço domingo?', 'sender_jid' => '5511933330000@s.whatsapp.net', 'sender_name' => 'Bia']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->assertSee('Bia:')
        ->call('openChat', $group->friendly_id)
        ->assertSeeHtml('data-chat-sender')
        ->assertSee('Almoço domingo?')
        ->set('text', 'Eu vou!')
        ->call('send');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendText/') && $request['number'] === '120363000000000001@g.us');
    expect($group->chatMessages()->where('from_me', true)->sole()->body)->toBe('Eu vou!');

    $this->actingAs($this->owner, 'sanctum')
        ->getJson(route('api.whatsapp.chats.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
