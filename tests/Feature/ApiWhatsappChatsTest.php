<?php

use App\Ai\Agents\WhatsappReplyAgent;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'UTC'));

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['name' => 'Paulo', 'timezone' => 'America/Sao_Paulo']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['nickname' => 'Personal']);
    $this->ana = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana']);
    $this->bia = WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bia']);

    $this->say = fn (WhatsappTarget $target, string $body, string $at, bool $fromMe = false): WhatsappChatMessage => WhatsappChatMessage::factory()->create([
        'whatsapp_target_id' => $target->id,
        'body' => $body,
        'from_me' => $fromMe,
        'sent_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
});

test('chats list the latest received first, with whether they await a reply', function () {
    ($this->say)($this->ana, 'Oi', '2026-10-05 10:00');
    ($this->say)($this->ana, 'Oi Ana!', '2026-10-05 10:05', fromMe: true);
    ($this->say)($this->bia, 'Vamos amanhã?', '2026-10-05 11:00');
    WhatsappTarget::factory()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Silent']);

    $other = WhatsappAccount::factory()->open()->create();
    ($this->say)(WhatsappTarget::factory()->create(['whatsapp_account_id' => $other->id]), 'not yours', '2026-10-05 11:30');

    $this->withToken($this->token)->getJson(route('api.whatsapp.chats.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Bia')
        ->assertJsonPath('data.0.awaiting_reply', true)
        ->assertJsonPath('data.0.last_received_message.body', 'Vamos amanhã?')
        ->assertJsonPath('data.0.account.nickname', 'Personal')
        ->assertJsonPath('data.1.name', 'Ana')
        ->assertJsonPath('data.1.awaiting_reply', false);
});

test('only the owner reads a chat or drafts a reply in it', function () {
    $message = ($this->say)($this->ana, 'Oi', '2026-10-05 10:00');
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create()->createToken('Pixel')->plainTextToken;
    WhatsappReplyAgent::fake()->preventStrayPrompts();

    $this->withToken($stranger)->getJson(route('api.whatsapp.chats.messages', $this->ana))->assertForbidden();
    $this->withToken($stranger)->postJson(route('api.whatsapp.chat-messages.reply', $message))->assertForbidden();

    app('auth')->forgetGuards();

    $this->withToken($this->token)->getJson(route('api.whatsapp.chats.messages', $this->ana))
        ->assertOk()
        ->assertJsonPath('data.0.friendly_id', $message->friendly_id)
        ->assertJsonPath('data.0.from_me', false);
});

test('a reply is drafted from the chat and the owner style, following the instruction', function () {
    AiProviderConnection::factory()->active()->create();
    ($this->say)($this->bia, 'kkk valeu demais', '2026-10-01 09:00', fromMe: true);
    ($this->say)($this->ana, 'Oi', '2026-10-05 10:00');
    ($this->say)($this->ana, 'blz, até', '2026-10-05 10:01', fromMe: true);
    $question = ($this->say)($this->ana, 'Vamos jantar sexta?', '2026-10-05 11:00');

    WhatsappReplyAgent::fake(['bora! sexta fechou 🍕'])->preventStrayPrompts();

    $this->withToken($this->token)
        ->postJson(route('api.whatsapp.chat-messages.reply', $question), ['instruction' => 'aceita'])
        ->assertOk()
        ->assertExactJson(['text' => 'bora! sexta fechou 🍕']);

    WhatsappReplyAgent::assertPrompted(fn ($prompt) => $prompt->contains('Vamos jantar sexta?')
        && $prompt->contains('- blz, até')
        && str_contains((string) $prompt->agent->instructions(), '- kkk valeu demais')
        && $prompt->contains('wants the reply to say: aceita'));
});

test('a reply is drafted only for what the contact sent, and only with an AI connection', function () {
    $mine = ($this->say)($this->ana, 'Oi', '2026-10-05 10:00', fromMe: true);
    $theirs = ($this->say)($this->ana, 'Oi!', '2026-10-05 10:01');
    WhatsappReplyAgent::fake()->preventStrayPrompts();

    $this->withToken($this->token)->postJson(route('api.whatsapp.chat-messages.reply', $mine))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');

    $this->withToken($this->token)->postJson(route('api.whatsapp.chat-messages.reply', $theirs))
        ->assertServiceUnavailable();

    WhatsappReplyAgent::assertNeverPrompted();
});
