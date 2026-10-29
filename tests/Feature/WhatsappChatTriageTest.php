<?php

use App\Actions\Whatsapp\RequestWhatsappChatTriage;
use App\Ai\Agents\WhatsappTriageAgent;
use App\Events\WhatsappChatsTriaged;
use App\Jobs\TriageWhatsappChats;
use App\Livewire\Whatsapp\Chat;
use App\Models\AiProviderConnection;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    FakeEvolutionApi::install();
    AiProviderConnection::factory()->active()->create();

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create();

    $this->chatWith = fn (string $name, array $attributes = []): WhatsappTarget => WhatsappTarget::factory()->contact()->create([
        'whatsapp_account_id' => $this->account->id,
        'name' => $name,
        'last_message_at' => now()->subHour(),
        ...$attributes,
    ]);
});

test('the AI prioritizes the unread chats picked, apart from the priority the user gave them', function () {
    Queue::fake([TriageWhatsappChats::class]);
    $ana = ($this->chatWith)('Ana', ['unread_count' => 2, 'priority' => 5, 'last_message_at' => now()->subMinutes(30)]);
    $bruno = ($this->chatWith)('Bruno', ['marked_unread' => true, 'priority' => 1, 'last_message_at' => now()->subMinutes(10)]);
    $carla = ($this->chatWith)('Carla', ['last_message_at' => now()->subMinutes(5)]);
    WhatsappChatMessage::factory()->for($ana, 'target')->create(['body' => 'O servidor caiu, preciso de você agora', 'sent_at' => now()->subMinutes(30)]);

    $screen = Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->assertSeeHtml('data-chat-select-pick="'.$carla->friendly_id.'"')
        ->call('triageChats', [$ana->friendly_id, $bruno->friendly_id, $carla->friendly_id])
        ->assertSet('notice', 'The AI is prioritizing 2 chats. The list sorts by it under “AI”.')
        ->assertSeeInIsland('data-chat-ai-pending="'.$ana->friendly_id.'"');

    expect($carla->fresh()->ai_triage_requested_at)->toBeNull();
    Queue::assertPushedOn('whatsapp', TriageWhatsappChats::class, fn (TriageWhatsappChats $job): bool => $job->chatIds === [$ana->id, $bruno->id]);

    WhatsappTriageAgent::fake([['chats' => [
        ['id' => $ana->friendly_id, 'urgency' => 'urgent', 'summary' => 'O servidor caiu e ela precisa de ajuda agora.'],
        ['id' => $bruno->friendly_id, 'urgency' => 'info', 'summary' => 'Só mandou um bom dia.'],
    ]]])->preventStrayPrompts();
    Event::fake([WhatsappChatsTriaged::class]);

    app()->call([new TriageWhatsappChats($this->owner, $this->account, [$ana->id, $bruno->id]), 'handle']);

    WhatsappTriageAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('O servidor caiu, preciso de você agora')
        && $prompt->contains('Chat id: '.$bruno->friendly_id)
        && ! $prompt->contains('Carla')
        && ! $prompt->contains('riority'));
    Event::assertDispatched(WhatsappChatsTriaged::class, fn (WhatsappChatsTriaged $event): bool => $event->userId === $this->owner->id && ! $event->failed);

    expect($ana->fresh())
        ->ai_urgency->toBe(1)
        ->ai_summary->toBe('O servidor caiu e ela precisa de ajuda agora.')
        ->ai_triage_requested_at->toBeNull()
        ->priority->toBe(5)
        ->and($bruno->fresh())->ai_urgency->toBe(4)->priority->toBe(1);

    $screen->call('chatsTriaged', ['account_id' => $this->account->friendly_id])
        ->assertSeeInIsland('data-chat-ai-urgency="'.$ana->friendly_id.'" data-urgency="urgent"')
        ->set('sort', Chat::SORT_AI)
        ->assertSeeInIsland('data-chat-ai-summary="'.$ana->friendly_id.'"');

    unset($screen->instance()->chats);
    expect($screen->instance()->chats->pluck('name')->all())->toBe(['Ana', 'Bruno', 'Carla']);

    $screen->set('sort', Chat::SORT_PRIORITY);
    unset($screen->instance()->chats);
    expect($screen->instance()->chats->pluck('name')->all())->toBe(['Bruno', 'Ana', 'Carla']);
});

test('a message after the AI read the chat leaves its urgency, out of date', function () {
    $ana = ($this->chatWith)('Ana', [
        'unread_count' => 1,
        'ai_urgency' => 2,
        'ai_summary' => 'Quer marcar a reunião.',
        'ai_triaged_at' => now()->subMinutes(20),
        'ai_triaged_through' => now()->subMinutes(30),
        'last_message_at' => now()->subMinutes(30),
    ]);

    expect($ana->aiTriageIsStale())->toBeFalse();

    $ana->update(['last_message_at' => now()]);

    expect($ana->fresh()->aiTriageIsStale())->toBeTrue();

    Livewire::actingAs($this->owner)->test(Chat::class)->assertSeeHtml('data-chat-ai-stale');
});

test('the AI is not asked without an AI set up, nor for too many chats, nor for another user\'s number', function () {
    $ana = ($this->chatWith)('Ana', ['unread_count' => 1]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('triageChats', WhatsappTarget::factory()->contact()->count(RequestWhatsappChatTriage::MAX_CHATS + 1)->create([
            'whatsapp_account_id' => $this->account->id,
            'unread_count' => 1,
            'last_message_at' => now(),
        ])->pluck('friendly_id')->all())
        ->assertSet('error', 'The AI prioritizes up to 20 chats at a time.');

    AiProviderConnection::query()->delete();

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('triageChats', [$ana->friendly_id])
        ->assertSet('error', 'No AI is set up for WhatsApp replies. Ask the administrator to set one.');

    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();

    expect(fn () => app(RequestWhatsappChatTriage::class)($stranger, $this->account, [$ana->friendly_id]))
        ->toThrow(AuthorizationException::class)
        ->and($ana->fresh()->ai_triage_requested_at)->toBeNull();
});

test('a triage the AI could not do stops waiting, and the screen says so', function () {
    Event::fake([WhatsappChatsTriaged::class]);
    $ana = ($this->chatWith)('Ana', ['unread_count' => 1, 'ai_triage_requested_at' => now()]);

    (new TriageWhatsappChats($this->owner, $this->account, [$ana->id]))->failed(new RuntimeException('timeout'));

    expect($ana->fresh()->ai_triage_requested_at)->toBeNull();
    Event::assertDispatched(WhatsappChatsTriaged::class, fn (WhatsappChatsTriaged $event): bool => $event->failed);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('chatsTriaged', ['account_id' => $this->account->friendly_id, 'failed' => true])
        ->assertSet('error', 'The AI could not prioritize the chats. Try again in a moment.');
});

test('a chat read forgets what the AI found, so that newer messages may be triaged again', function () {
    $triaged = [
        'ai_urgency' => 1,
        'ai_summary' => 'Precisa de ajuda.',
        'ai_triaged_at' => now(),
        'ai_triaged_through' => now()->subHour(),
    ];
    $ana = ($this->chatWith)('Ana', ['unread_count' => 2, ...$triaged]);
    $bruno = ($this->chatWith)('Bruno', ['unread_count' => 1, 'priority' => 2, ...$triaged]);

    Livewire::actingAs($this->owner)->test(Chat::class)->call('openChat', $ana->friendly_id);

    expect($ana->fresh())->ai_urgency->toBeNull()->ai_summary->toBeNull()->ai_triaged_at->toBeNull()
        ->and($bruno->fresh()->ai_urgency)->toBe(1);

    // Read on the phone, or answered from any device.
    $bruno->update(['unread_count' => 0]);

    expect($bruno->fresh())->ai_urgency->toBeNull()->priority->toBe(2);
});

test('a chat read while the AI was at it keeps nothing of it', function () {
    $ana = ($this->chatWith)('Ana', ['unread_count' => 1, 'ai_triage_requested_at' => now()]);
    WhatsappTriageAgent::fake([['chats' => [['id' => $ana->friendly_id, 'urgency' => 'urgent', 'summary' => 'Ajuda.']]]]);

    $job = new TriageWhatsappChats($this->owner, $this->account, [$ana->id]);
    $ana->update(['unread_count' => 0]);
    app()->call([$job, 'handle']);

    expect($ana->fresh())->ai_urgency->toBeNull()->ai_triage_requested_at->toBeNull();
});
