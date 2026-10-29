<?php

use App\Jobs\SendWhatsappPresence;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['whatsapp_priority_colors' => [1 => 'pink']]);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal', 'instance_name' => 'jarvis']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()->subHour(), 'unread_count' => 2, 'priority' => 1]);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Family', 'last_message_at' => now()->subMinutes(5)]);
});

test('the chats come pinned first, then the latest message first, contacts and groups, only the owner\'s', function () {
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bia', 'pinned_at' => now()]);
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Silent']);
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => WhatsappAccount::factory()->open()->create()->id, 'last_message_at' => now()]);
    $tag = WhatsappTag::factory()->for($this->owner)->create(['name' => 'Clientes', 'color' => 'orange']);
    $this->ana->tags()->attach($tag);
    WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Oi Ana', 'receipt' => WhatsappChatMessage::RECEIPT_READ]);

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index'))
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Bia', 'Family', 'Ana'])
        ->assertJsonPath('data.0.pinned', true)
        ->assertJsonPath('data.1.is_group', true)
        ->assertJsonPath('data.2.unread_count', 2)
        ->assertJsonPath('data.2.priority', 1)
        ->assertJsonPath('data.2.priority_color', WhatsappTarget::PRIORITY_COLORS['pink'])
        ->assertJsonPath('data.2.tags.0.name', 'Clientes')
        ->assertJsonPath('data.2.last_message.body', 'Oi Ana')
        ->assertJsonPath('data.2.last_message.receipt', 'read')
        ->assertJsonPath('data.2.account.nickname', 'Personal');

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index', ['search' => 'fam']))
        ->assertJsonPath('data.*.name', ['Family']);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index', ['tag' => $tag->friendly_id]))
        ->assertJsonPath('data.*.name', ['Ana']);

});

test('the messages page backwards, with the group sender, ticks and a signed media url', function () {
    foreach (range(1, 45) as $minute) {
        WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => "M{$minute}", 'body' => "line {$minute}", 'sent_at' => now()->subHour()->addMinutes($minute), 'sender_jid' => '5511955550000@s.whatsapp.net', 'sender_name' => 'Carla']);
    }
    $audio = WhatsappChatMessage::factory()->audio()->create(['whatsapp_target_id' => $this->family->id, 'sent_at' => now(), 'media_seconds' => 7, 'media_size' => 1000]);

    $first = $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->family))
        ->assertOk()
        ->assertJsonCount(40, 'data')
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('outgoing', [])
        ->assertJsonPath('data.0.friendly_id', $audio->friendly_id)
        ->assertJsonPath('data.0.media.kind', 'audio')
        ->assertJsonPath('data.0.media.seconds', 7)
        ->assertJsonCount(40, 'data.0.media.waveform')
        ->assertJsonPath('data.1.sender', 'Carla')
        ->assertJsonPath('data.1.body', 'line 45');

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', [$this->family, 'before' => $first->json('data.39.friendly_id')]))
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('meta.has_more', false)
        ->assertJsonPath('data.5.body', 'line 1');

    // The file comes from the signed url alone, fetched from WhatsApp once.
    Storage::fake((string) config('filesystems.private'));
    $url = $first->json('data.0.media.url');

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/chat/getBase64FromMediaMessage/')))->toHaveCount(1);
});

test('a text is sent from the chat number, and pending until whatsapp reports it', function () {
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.send', $this->ana), ['text' => '  '])
        ->assertUnprocessable();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.send', $this->ana), ['text' => 'Tudo certo!'])
        ->assertAccepted()
        ->assertJsonPath('data.body', 'Tudo certo!');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendText/') && $request['text'] === 'Tudo certo!');
});

test('reading a chat zeroes its unread count and tells whatsapp only when it sends read receipts', function () {
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HI']);

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.read', $this->ana))->assertNoContent();

    expect($this->ana->fresh()->unread_count)->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/chat/markMessageAsRead/'));

    $this->ana->update(['sends_read_receipts' => true]);
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.read', $this->ana))->assertNoContent();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/markMessageAsRead/'));

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.typing', $this->ana))
        ->assertOk()
        ->assertJson(['shown' => false]);
});

test('nobody else reads, sends to or reads the files of a chat', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $token = $stranger->createToken('Pixel')->plainTextToken;
    $message = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id]);

    $this->withToken($token)->getJson(route('api.whatsapp.conversations.index'))->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson(route('api.whatsapp.conversations.messages', $this->ana))->assertForbidden();
    $this->withToken($token)->postJson(route('api.whatsapp.conversations.send', $this->ana), ['text' => 'Oi'])->assertForbidden();
    $this->withToken($token)->postJson(route('api.whatsapp.conversations.read', $this->ana))->assertForbidden();
    $this->getJson(route('api.whatsapp.media', $message))->assertForbidden();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/message/sendText/'));
});

test('opening a chat asks whatsapp for its presence, at most every ten minutes', function () {
    Bus::fake();

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->ana))->assertOk();
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->ana))->assertOk();

    Bus::assertDispatchedAfterResponseTimes(SendWhatsappPresence::class, 1);

    $this->travel(11)->minutes();
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->ana))->assertOk();

    Bus::assertDispatchedAfterResponseTimes(SendWhatsappPresence::class, 2);
});
