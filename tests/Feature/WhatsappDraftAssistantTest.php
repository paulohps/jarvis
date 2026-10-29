<?php

use App\Actions\Whatsapp\EditWhatsappDraftMessage;
use App\Ai\Agents\WhatsappDraftTitleAgent;
use App\Ai\Agents\WhatsappReplyAgent;
use App\Jobs\NameWhatsappDraftConversation;
use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\DraftAssistant;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappDraftMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Messages\Message;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();
    AiProviderConnection::factory()->active()->create();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'last_message_at' => now()]);
});

test('a conversation with the AI writes the message, takes changes, and knows the hand edits', function () {
    WhatsappReplyAgent::fake(['bora sim!', 'Bora sim, sexta 13h fechado!'])->preventStrayPrompts();
    WhatsappChatMessage::factory()->for($this->ana, 'target')->create(['body' => 'Sexta 13h?', 'sent_at' => now()->subHour()]);

    $panel = Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->call('open')
        ->assertSet('show', true)
        ->set('request', 'aceita')
        ->call('ask')
        ->assertSet('request', '')
        ->assertSeeHtml('data-chat-draft-use=');

    WhatsappReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('Message to answer (from Ana): Sexta 13h?')
        && $prompt->contains('What '.$this->owner->name.' asks now: aceita'));

    $conversation = WhatsappDraftConversation::query()->sole();
    $written = $conversation->messages()->where('role', WhatsappDraftMessage::ASSISTANT)->sole();
    expect($conversation->title)->toBe('aceita')
        ->and($written->body)->toBe('bora sim!');

    $panel->assertSeeHtml('data-chat-draft-edit="'.$written->friendly_id.'"')
        ->call('saveEditing', $written->friendly_id, 'Bora sim!')
        ->assertSeeHtml('data-chat-draft-edited')
        ->set('request', 'diz que sexta 13h está fechado')
        ->call('ask');

    WhatsappReplyAgent::assertPrompted(function ($prompt): bool {
        $history = array_map(fn (Message $message): array => [$message->role->value, $message->content], [...$prompt->agent->messages()]);

        return $history === [['user', 'aceita'], ['assistant', 'Bora sim!']]
            && $prompt->contains('edited your last message by hand')
            && $prompt->contains('Write the whole message again');
    });

    expect($conversation->messages()->count())->toBe(4);

    $panel->call('useMessage', $conversation->messages->last()->friendly_id)
        ->assertSet('show', false)
        ->assertDispatched('whatsapp-use-draft', text: 'Bora sim, sexta 13h fechado!');

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->dispatch('whatsapp-use-draft', text: 'Bora sim, sexta 13h fechado!')
        ->assertSet('text', 'Bora sim, sexta 13h fechado!')
        ->assertDispatched('whatsapp-chat-drafted');
});

test('the AI answers the picked messages together, or writes from the request alone', function () {
    WhatsappReplyAgent::fake(['pode deixar', 'oi Ana'])->preventStrayPrompts();
    $first = WhatsappChatMessage::factory()->for($this->ana, 'target')->create(['body' => 'Vamos almoçar?', 'sent_at' => now()->subHours(2)]);
    $second = WhatsappChatMessage::factory()->for($this->ana, 'target')->create(['body' => 'Sexta 13h?', 'sent_at' => now()->subHour()]);

    Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->call('open', [$second->friendly_id, $first->friendly_id])
        ->assertSeeHtml('data-chat-draft-reply-to')
        ->set('request', 'aceita')
        ->call('ask')
        ->call('startNew')
        ->set('readsChat', false)
        ->set('request', 'diz oi')
        ->call('ask');

    WhatsappReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains("Messages to answer together, oldest first:\n- Ana: Vamos almoçar?\n- Ana: Sexta 13h?"));
    WhatsappReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('Do not read into the chat') && ! $prompt->contains('Sexta 13h?'));

    expect(WhatsappDraftConversation::query()->count())->toBe(2);
});

test('the panel always opens on a new conversation, the past ones staying in the history', function () {
    $conversation = WhatsappDraftConversation::factory()->for($this->owner)->for($this->ana, 'chat')->create();

    Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->call('openConversation', $conversation->friendly_id)
        ->set('show', false)
        ->call('open')
        ->assertSet('conversationId', null)
        ->assertSet('show', true);
});

test('a message sent from the AI leads back to its conversation, and the next one it writes edits the sent one', function () {
    WhatsappReplyAgent::fake(['Bora, sexta 13h!'])->preventStrayPrompts();
    $conversation = WhatsappDraftConversation::factory()->for($this->owner)->for($this->ana, 'chat')->create();
    WhatsappDraftMessage::factory()->for($conversation, 'conversation')->create(['role' => WhatsappDraftMessage::USER, 'body' => 'aceita']);
    $written = WhatsappDraftMessage::factory()->fromAssistant()->for($conversation, 'conversation')->create(['body' => 'Bora!']);

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->dispatch('whatsapp-use-draft', text: 'Bora!', draftMessageId: $written->friendly_id)
        ->call('send')
        ->assertSet('draftMessageId', null);

    $delivery = $written->fresh()->delivery;
    expect($delivery)->not->toBeNull();

    $sent = WhatsappChatMessage::factory()->fromMe()->for($this->ana, 'target')->create(['body' => 'Bora!', 'external_id' => 'SENT1', 'sent_at' => now()]);
    $delivery->update(['external_message_id' => 'SENT1']);

    $screen->call('$refresh')
        ->assertSeeHtml('data-chat-drafted="'.$conversation->friendly_id.'"');

    $panel = Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->call('open', [], $conversation->friendly_id, $sent->friendly_id)
        ->assertSet('conversationId', $conversation->friendly_id)
        ->assertSeeHtml('data-chat-draft-sent')
        ->set('request', 'diz o horário')
        ->call('ask');

    $panel->call('useMessage', $conversation->messages()->get()->last()->friendly_id)
        ->assertDispatched('whatsapp-edit-sent', messageId: $sent->friendly_id, text: 'Bora, sexta 13h!');

    $screen->dispatch('whatsapp-edit-sent', messageId: $sent->friendly_id, text: 'Bora, sexta 13h!')
        ->assertSet('showEdit', true)
        ->assertSet('editText', 'Bora, sexta 13h!');

    // Past WhatsApp's edit window, it goes to the composer instead.
    $this->travel(16)->minutes();
    $panel->call('open', [], $conversation->friendly_id, $sent->friendly_id)
        ->call('useMessage', $written->friendly_id)
        ->assertDispatched('whatsapp-use-draft', text: 'Bora!', draftMessageId: $written->friendly_id);
});

test('in a group the AI is told who sent each message and mentions the members the request mentions', function () {
    WhatsappReplyAgent::fake(['@Bia Souza e @5511944440000, domingo 12h?'])->preventStrayPrompts();

    $group = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Família', 'last_message_at' => now()]);
    WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bia Souza', 'jid' => '5511933330000@s.whatsapp.net']);
    WhatsappChatMessage::factory()->for($group, 'target')->create(['body' => 'Quem vem domingo?', 'sender_jid' => '5511933330000@s.whatsapp.net', 'sender_name' => 'Bia', 'sent_at' => now()->subHour()]);
    WhatsappChatMessage::factory()->for($group, 'target')->create(['body' => 'Eu levo a sobremesa', 'sender_jid' => '5511944440000@s.whatsapp.net', 'sender_name' => 'Caio', 'sent_at' => now()->subMinutes(5)]);

    $panel = Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $group->friendly_id])
        ->set('request', str_repeat('a', 2001))
        ->call('ask')
        ->assertSet('error', 'The request can have up to 2000 characters.');

    $panel->set('request', 'chama @5511933330000 e @5511944440000 pro almoço '.str_repeat('a', 1900))
        ->call('ask')
        ->assertSet('error', null);

    WhatsappReplyAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('] Bia: Quem vem domingo?')
        && $prompt->contains("- @5511933330000 (Bia Souza)\n- @5511944440000 (Caio)"));

    expect(WhatsappDraftMessage::query()->where('role', WhatsappDraftMessage::ASSISTANT)->value('body'))
        ->toBe('@5511933330000 e @5511944440000, domingo 12h?');
});

test('when the AI fails the request is taken back', function () {
    WhatsappReplyAgent::fake(fn () => throw new RuntimeException('down'));

    Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->set('request', 'diz oi')
        ->call('ask')
        ->assertSet('error', 'The assistant could not write a reply right now. Try again in a moment.')
        ->assertSet('request', 'diz oi');

    expect(WhatsappDraftConversation::query()->count())->toBe(0)
        ->and(WhatsappDraftMessage::query()->count())->toBe(0);
});

test('a new conversation is named by the AI, and the past ones are listed, continued and deleted', function () {
    WhatsappDraftTitleAgent::fake(['"Almoço de sexta."']);
    $conversation = WhatsappDraftConversation::factory()->for($this->owner)->for($this->ana, 'chat')->create(['title' => 'aceita o almoço']);
    WhatsappDraftMessage::factory()->for($conversation, 'conversation')->create(['role' => WhatsappDraftMessage::USER, 'body' => 'aceita o almoço']);

    (new NameWhatsappDraftConversation($conversation))->handle();

    expect($conversation->fresh()->title)->toBe('Almoço de sexta');

    Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->set('showHistory', true)
        ->assertSeeHtml('data-chat-draft-conversation="'.$conversation->friendly_id.'"')
        ->assertSee('Almoço de sexta')
        ->call('openConversation', $conversation->friendly_id)
        ->assertSet('conversationId', $conversation->friendly_id)
        ->assertSet('showHistory', false)
        ->call('deleteConversation', $conversation->friendly_id)
        ->assertSet('conversationId', null);

    expect(WhatsappDraftConversation::query()->count())->toBe(0);
});

test('nobody sees, continues or edits a conversation of somebody else', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $theirs = WhatsappAccount::factory()->open()->for($stranger)->create();
    $theirChat = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $theirs->id]);

    $conversation = WhatsappDraftConversation::factory()->for($this->owner)->for($this->ana, 'chat')->create();
    $written = WhatsappDraftMessage::factory()->fromAssistant()->for($conversation, 'conversation')->create();
    $asked = WhatsappDraftMessage::factory()->for($conversation, 'conversation')->create(['role' => WhatsappDraftMessage::USER]);

    Livewire::actingAs($stranger)
        ->test(DraftAssistant::class, ['chatId' => $theirChat->friendly_id])
        ->call('openConversation', $conversation->friendly_id)
        ->assertNotFound();

    expect(fn () => app(EditWhatsappDraftMessage::class)($stranger, $written, 'oi'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EditWhatsappDraftMessage::class)($this->owner, $asked, 'oi'))->toThrow(AuthorizationException::class);

    Livewire::actingAs($stranger)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->assertNotFound();
});

test('a message the model cut short (out of tokens, its reasoning counted) is not kept, and the owner is told', function () {
    $answer = fn (string $text, string $status, int $output, int $reasoning): array => [
        'id' => 'resp_1',
        'object' => 'response',
        'status' => $status,
        'model' => 'gpt-6-luna',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'message', 'id' => 'msg_1', 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
        ],
        'usage' => ['input_tokens' => 3000, 'output_tokens' => $output, 'output_tokens_details' => ['reasoning_tokens' => $reasoning]],
    ];
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push($answer('Pessoal, boa tarde! Neste sábado o', 'incomplete', 8000, 7900))
        ->push($answer('Pessoal, boa tarde! Neste sábado começa o mutirão.', 'completed', 900, 860))
        ->push($answer('Mutirão da igreja', 'completed', 300, 296))]);

    $panel = Livewire::actingAs($this->owner)
        ->test(DraftAssistant::class, ['chatId' => $this->ana->friendly_id])
        ->call('open')
        ->set('readsChat', false)
        ->set('request', 'avisa do mutirão')
        ->call('ask')
        ->assertSet('error', __('The message came out too long and was cut short. Ask for a shorter one, or try again.'))
        ->assertSet('request', 'avisa do mutirão');

    expect(WhatsappDraftConversation::query()->count())->toBe(0);
    Http::assertSent(fn (HttpRequest $request): bool => $request['max_output_tokens'] === WhatsappReplyAgent::MAX_TOKENS);

    $panel->call('ask')->assertSet('error', null);

    expect(WhatsappDraftMessage::query()->where('role', WhatsappDraftMessage::ASSISTANT)->sole()->body)->toBe('Pessoal, boa tarde! Neste sábado começa o mutirão.');
});
