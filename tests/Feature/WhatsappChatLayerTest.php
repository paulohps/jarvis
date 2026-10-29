<?php

use App\Actions\Whatsapp\PinWhatsappChat;
use App\Actions\Whatsapp\SetWhatsappChatNickname;
use App\Livewire\Chats\ChatChannelList;
use App\Livewire\Chats\ChatsPage;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\ChatTag;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use App\Models\Workspace;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Support\LookupCache;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Storage::fake((string) config('filesystems.private'));
    FakeEvolutionApi::install();
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS, Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $whatsapp = ChatProvider::idFor(ChatProvider::WHATSAPP);
    $this->workspace->chatProviders()->sync([$whatsapp => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->memberships()->sole()->chatProviders()->sync([$whatsapp => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->forgetMemberships();

    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis', 'phone_number' => '+5511900000000', 'nickname' => 'Pessoal']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'unread_count' => 2]);
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net']);
    $this->hi = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HI1', 'body' => 'Oi, tudo bem?', 'sent_at' => now()->subMinute()]);
    // As the webhook does once the message is kept: the chat gets its moment, and with it its entry.
    $this->ana->forceFill(['last_message_at' => now()->subMinute()])->save();

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
    $this->sent = fn (string $path) => Http::recorded(fn (Request $request): bool => str_contains($request->url(), $path))->map(fn (array $pair) => $pair[0]);
});

test('a whatsapp chat is an entry of the chat screen that follows the chat', function () {
    $entry = $this->ana->conversation;

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->provider->slug)->toBe(ChatProvider::WHATSAPP)
        ->and($entry->kind)->toBe(ChatConversation::KIND_DIRECT)
        ->and($entry->name)->toBe('Ana')
        ->and($entry->unread_count)->toBe(2)
        ->and($this->bruno->conversation)->toBeNull();

    // A message coming in: the count, the moment and the preview follow.
    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->ana->jid, 'fromMe' => false, 'id' => 'HI2'],
        'pushName' => 'Ana',
        'messageType' => 'conversation',
        'message' => ['conversation' => 'Almoço?'],
        'messageTimestamp' => now()->getTimestamp(),
    ])->assertOk();

    expect($entry->refresh())
        ->unread_count->toBe(3)
        ->and($entry->last_message['text'])->toBe('Almoço?')
        ->and($entry->last_message['from_me'])->toBeFalse()
        ->and($entry->last_message_at?->toIso8601String())->toBe(now()->toIso8601String());

    // What the WhatsApp screen sets shows on the entry; a first message gives a chat its entry.
    app(PinWhatsappChat::class)($this->owner, $this->ana, true);
    app(SetWhatsappChatNickname::class)($this->owner, $this->ana, 'Aninha');
    $this->bruno->forceFill(['last_message_at' => now()])->save();

    expect($entry->refresh())
        ->isPinned()->toBeTrue()
        ->nickname->toBe('Aninha')
        ->displayName()->toBe('Aninha')
        ->and($this->bruno->refresh()->conversation?->name)->toBe('Bruno');

    // Opening the chat on the WhatsApp screen reads it on both.
    $this->ana->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
    expect($entry->refresh()->isUnread())->toBeFalse();
});

test('deleting the chat or the number takes the entries along', function () {
    $entryId = $this->ana->conversation->id;
    $this->ana->delete();
    expect(ChatConversation::query()->whereKey($entryId)->exists())->toBeFalse();

    $carla = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Carla', 'last_message_at' => now()]);
    expect($carla->conversation)->not->toBeNull();

    $this->account->delete();
    expect(ChatConversation::query()->count())->toBe(0);
});

test('the chat screen lists the whatsapp chats with their mark, opens them and sends through whatsapp', function () {
    $entry = $this->ana->conversation;

    $this->actingAs($this->owner)->get(route('chats.chat'))
        ->assertOk()
        ->assertSee("data-chat-item=\"{$entry->friendly_id}\"", false)
        ->assertSee('data-chat-item-mark="whatsapp"', false)
        ->assertSee("data-chat-unread=\"{$entry->friendly_id}\"", false)
        ->assertSee('Oi, tudo bem?');

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $entry->friendly_id])
        ->assertSee('Oi, tudo bem?')
        ->assertDontSee('data-chat-thread-open', false)
        ->set('text', 'Tudo! E você?')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('text', '')
        ->assertSee('Tudo! E você?');

    expect(($this->sent)('/message/sendText/jarvis'))->toHaveCount(1)
        ->and($this->ana->refresh()->unread_count)->toBe(0)
        ->and($this->ana->chatMessages()->where('from_me', true)->value('body'))->toBe('Tudo! E você?');

    // What the WhatsApp side tells of the chat refreshes the open conversation.
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $entry->friendly_id])
        ->call('whatsappChatUpdated', ['chat_id' => $this->ana->friendly_id])
        ->assertDispatched('chat-received');
});

test('the api lists the whatsapp conversation with its capabilities and takes a message', function () {
    Sanctum::actingAs($this->owner);
    $entry = $this->ana->conversation;

    $this->getJson('/api/v1/chats/providers')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'whatsapp')
        ->assertJsonPath('data.0.capabilities.threads', false)
        ->assertJsonPath('data.0.capabilities.formatting', 'whatsapp')
        ->assertJsonPath('data.0.accounts.0.label', 'Pessoal');

    $this->getJson("/api/v1/chats/conversations/{$entry->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.provider', 'whatsapp')
        ->assertJsonPath('data.name', 'Ana')
        ->assertJsonPath('data.capabilities.send', true)
        ->assertJsonPath('data.capabilities.mentions', false);

    $this->postJson("/api/v1/chats/conversations/{$entry->friendly_id}/messages", ['text' => 'Oi!'])
        ->assertCreated()
        ->assertJsonPath('data.from_me', true)
        ->assertJsonPath('data.text', 'Oi!');

    $this->patchJson("/api/v1/chats/conversations/{$entry->friendly_id}", ['pinned' => true, 'priority' => 1])
        ->assertOk()
        ->assertJsonPath('data.priority', 1);

    expect($this->ana->refresh())->isPinned()->toBeTrue()->priority->toBe(1);
});

test('the channels screen shows the whatsapp number with a link to manage it', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatChannelList::class)
        ->assertSee('Pessoal')
        ->assertSee('data-account-state="connected"', false)
        ->assertSee("data-account-manage=\"{$this->account->friendly_id}\"", false)
        ->assertSee('data-whatsapp-connect', false)
        ->assertDontSee('data-slack-connect', false);
});

test('the migration gives the chats already known their entries and brings the tags along', function () {
    $tag = WhatsappTag::factory()->for($this->owner)->create(['name' => 'Clientes', 'color' => 'blue']);
    $this->ana->tags()->attach($tag);
    $this->bruno->tags()->attach($tag);
    $pinnedOnly = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Dora', 'pinned_at' => now()]);
    ChatConversation::query()->delete();
    // As on a database deployed before the chat layer: the seeder only runs after the migrations.
    ChatProvider::query()->delete();
    app(LookupCache::class)->flush();

    $migration = require database_path('migrations/2026_10_09_200000_adopt_whatsapp_chats_on_the_chat_layer.php');
    $migration->up();
    $migration->up();

    $entries = ChatConversation::query()->with('tags')->get()->keyBy('conversable_id');

    expect($entries)->toHaveCount(2)
        ->and($entries[$this->ana->id]->name)->toBe('Ana')
        ->and($entries[$this->ana->id]->unread_count)->toBe(2)
        ->and($entries[$this->ana->id]->tags->pluck('name')->all())->toBe(['Clientes'])
        ->and($entries[$pinnedOnly->id]->isPinned())->toBeTrue()
        ->and(ChatTag::query()->where('user_id', $this->owner->id)->get()->map(fn (ChatTag $tag): array => [$tag->name, $tag->color])->all())->toBe([['Clientes', 'blue']])
        ->and(ChatProvider::query()->orderBy('sort_order')->get()->map(fn (ChatProvider $provider): array => [$provider->slug, $provider->is_active])->all())->toBe([['whatsapp', true], ['slack', true]]);
});
