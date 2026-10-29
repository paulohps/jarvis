<?php

use App\Ai\Agents\WhatsappReplyAgent;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();

    $this->owner = User::factory()->withModules([Module::WHATSAPP, Module::REMINDERS])->create(['timezone' => 'UTC']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()->subHour()]);
    $this->bia = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bia', 'last_message_at' => now()]);
});

test('a chat is pinned, prioritized, nicknamed, tagged and set from the app', function () {
    $tag = $this->withToken($this->token)->postJson(route('api.whatsapp.tags.store'), ['name' => 'Clientes', 'color' => 'orange'])
        ->assertCreated()
        ->assertJsonPath('data.color_name', 'orange')
        ->json('data.friendly_id');
    $this->withToken($this->token)->postJson(route('api.whatsapp.tags.store'), ['name' => 'clientes', 'color' => 'red'])->assertUnprocessable();

    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.update', $this->ana), [
        'pinned' => true,
        'priority' => 2,
        'nickname' => 'Aninha',
        'tags' => [$tag],
        'sends_read_receipts' => true,
        'notifies_messages' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.priority', 2)
        ->assertJsonPath('data.name', 'Aninha')
        ->assertJsonPath('data.whatsapp_name', 'Ana')
        ->assertJsonPath('data.tags.0.name', 'Clientes')
        ->assertJsonPath('data.settings.sends_read_receipts', true)
        ->assertJsonPath('data.settings.sends_typing', null)
        ->assertJsonPath('data.settings.effective.notifies_messages', false);

    // Fields left out stay; null follows the number again.
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.update', $this->ana), ['sends_read_receipts' => null, 'priority' => null])
        ->assertJsonPath('data.pinned', true)
        ->assertJsonPath('data.priority', null)
        ->assertJsonPath('data.settings.sends_read_receipts', null);

    // The highest priority first after the pinned ones.
    $this->bia->update(['priority' => 1]);
    $carla = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Carla', 'last_message_at' => now()->addMinute()]);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index', ['sort' => 'priority']))
        ->assertJsonPath('data.*.name', ['Aninha', 'Bia', 'Carla']);

    $this->withToken($this->token)->patchJson(route('api.whatsapp.tags.update', $tag), ['color' => 'blue'])->assertJsonPath('data.color_name', 'blue');
    $this->withToken($this->token)->deleteJson(route('api.whatsapp.tags.destroy', $tag))->assertNoContent();
    expect($this->ana->fresh()->tags)->toBeEmpty()
        ->and($carla->exists)->toBeTrue();
});

test('the priority colors and the defaults of a number are set from the app', function () {
    $this->withToken($this->token)->putJson(route('api.whatsapp.priority-colors.update'), ['colors' => ['1' => 'pink']])
        ->assertOk()
        ->assertJsonPath('colors.1', 'pink')
        ->assertJsonPath('colors.2', 'orange')
        ->assertJsonPath('palette.pink', WhatsappTarget::PRIORITY_COLORS['pink']);
    $this->withToken($this->token)->putJson(route('api.whatsapp.priority-colors.update'), ['colors' => ['1' => 'violet']])->assertUnprocessable();

    $this->withToken($this->token)->patchJson(route('api.whatsapp.chat-settings.update', $this->account), ['sends_typing' => true, 'notifies_messages' => false])
        ->assertOk()
        ->assertJsonPath('data.chat_settings', ['sends_read_receipts' => false, 'sends_typing' => true, 'notifies_messages' => false, 'syncs_phone_reads' => true]);

    $this->withToken($this->token)->getJson(route('api.whatsapp.accounts.index'))
        ->assertJsonPath('data.0.chat_settings.sends_typing', true);
});

test('a chat is started with a number, synced, and gets a reminder and a scheduled message', function () {
    $chat = $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.start'), ['account' => $this->account->friendly_id, 'number' => '+55 11 93333-0000'])
        ->assertCreated()
        ->json('data.friendly_id');

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.sync', $chat))->assertOk()->assertJsonStructure(['added']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/chat/findMessages/'));

    $later = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Depois das 10', 'sent_at' => now()]);
    $first = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Me liga amanhã', 'sent_at' => now()->subMinute()]);
    $elsewhere = WhatsappChatMessage::factory()->create();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.remind', $this->ana), ['title' => 'Ligar para Ana', 'due_at' => '2026-10-07T09:00', 'messages' => [$first->friendly_id, $elsewhere->friendly_id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('messages');

    // Every picked message is quoted, in the order they were sent.
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.remind', $this->ana), ['title' => 'Ligar para Ana', 'due_at' => '2026-10-07T09:00', 'messages' => [$later->friendly_id, $first->friendly_id]])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Ligar para Ana');

    expect(Reminder::query()->sole()->note)->toEndWith("“Me liga amanhã”\n“Depois das 10”")
        ->and($this->ana->reminders()->sole()->pivot->whatsapp_chat_message_id)->toBe($later->id);

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.schedule', $this->ana), ['text' => 'Bom dia!', 'at' => '2026-10-01T09:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('at');
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.schedule', $this->ana), ['text' => 'Bom dia!', 'at' => '2026-10-07T09:00'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Bom dia!');

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.show', $this->ana))
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana')
        ->assertJsonPath('reminders.0.title', 'Ligar para Ana')
        ->assertJsonPath('scheduled.0.body', 'Bom dia!');

    expect(WhatsappMessage::query()->sole()->body)->toBe('Bom dia!');
});

test('the ai drafts an answer to the picked messages, or the latest received', function () {
    AiProviderConnection::factory()->active()->create();
    $picked = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Vamos jantar sexta?']);

    WhatsappReplyAgent::fake(['bora!'])->preventStrayPrompts();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.draft', $this->ana), ['messages' => [$picked->friendly_id], 'instruction' => 'aceita'])
        ->assertOk()
        ->assertExactJson(['text' => 'bora!']);

    WhatsappReplyAgent::assertPrompted(fn ($prompt) => $prompt->contains('Vamos jantar sexta?'));

    // Nothing picked: what Ana sent since the last answer, with no instruction.
    WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Oi Ana', 'sent_at' => now()->subMinutes(5)]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Antes da resposta', 'sent_at' => now()->subMinutes(10)]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Tem cinema também', 'sent_at' => now()->subMinutes(2)]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Ou teatro', 'sent_at' => now()->subMinute()]);
    WhatsappReplyAgent::fake(['pode ser!'])->preventStrayPrompts();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.draft', $this->ana))
        ->assertOk()
        ->assertExactJson(['text' => 'pode ser!']);

    WhatsappReplyAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "Messages to answer together, oldest first:\n- Ana: Tem cinema também\n- Ana: Ou teatro\n\n"));

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.draft', $this->bia))->assertUnprocessable();
});

test('nobody else changes or reads the tools of a chat', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $token = $stranger->createToken('Pixel')->plainTextToken;
    $tag = WhatsappTag::factory()->for($this->owner)->create();

    $this->withToken($token)->getJson(route('api.whatsapp.conversations.show', $this->ana))->assertForbidden();
    $this->withToken($token)->patchJson(route('api.whatsapp.conversations.update', $this->ana), ['pinned' => true])->assertForbidden();
    $this->withToken($token)->patchJson(route('api.whatsapp.chat-settings.update', $this->account), ['sends_typing' => true])->assertForbidden();
    $this->withToken($token)->deleteJson(route('api.whatsapp.tags.destroy', $tag))->assertForbidden();
    $this->withToken($token)->getJson(route('api.whatsapp.tags.index'))->assertOk()->assertJsonCount(0, 'data');

    expect($this->ana->fresh()->pinned_at)->toBeNull()
        ->and($tag->fresh())->not->toBeNull();
});
