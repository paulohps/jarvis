<?php

use App\Actions\Chats\ApplyChatConversationsBulkAction;
use App\Actions\Chats\RequestChatTriage;
use App\Ai\Agents\ChatTriageAgent;
use App\Events\ChatConversationsTriaged;
use App\Events\ChatConversationUpdated;
use App\Events\WhatsappChatUpdated;
use App\Jobs\TriageChatConversations;
use App\Livewire\Chats\ChatsPage;
use App\Models\AiProviderConnection;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fixtures\Slack\FakeSlackApi;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));
    Event::fake([ChatConversationUpdated::class, WhatsappChatUpdated::class]);
    FakeEvolutionApi::install();
    // Each fake starts over, so Slack only answers that it took the change.
    Http::fake(['https://slack.com/api/*' => Http::response(['ok' => true])]);
    AiProviderConnection::factory()->active()->create();

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS, Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $providers = collect([ChatProvider::SLACK, ChatProvider::WHATSAPP])
        ->mapWithKeys(fn (string $slug): array => [ChatProvider::idFor($slug) => ['enabled_by_id' => $this->owner->id]])
        ->all();
    $this->workspace->chatProviders()->sync($providers);
    $this->owner->memberships()->sole()->chatProviders()->sync($providers);
    $this->owner->forgetMemberships();

    $slackAccount = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => 'acme']);
    SlackUser::factory()->for($slackAccount, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    $dm = SlackConversation::factory()->for($slackAccount, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID]);
    SlackMessage::factory()->for($dm, 'conversation')->create(['ts' => '1700000100.000100', 'slack_user_id' => FakeSlackApi::ANA_ID, 'text' => 'O deploy quebrou a produção', 'sent_at' => now()->subMinutes(30)]);
    $this->ana = ChatConversation::factory()->for($this->owner)->forSlack($dm)->direct()->create(['name' => 'Ana Lima', 'unread_count' => 1, 'priority' => 5, 'last_message_at' => now()->subMinutes(30)]);
    $general = SlackConversation::factory()->for($slackAccount, 'account')->create(['channel_id' => FakeSlackApi::CHANNEL_ID, 'name' => 'general']);
    $this->general = ChatConversation::factory()->for($this->owner)->forSlack($general)->create(['name' => '#general', 'last_message_at' => now()->subMinutes(5)]);

    $whatsappAccount = WhatsappAccount::factory()->open()->for($this->owner)->create();
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $whatsappAccount->id, 'name' => 'Bruno', 'unread_count' => 1, 'priority' => 1]);
    WhatsappChatMessage::factory()->for($this->bruno, 'target')->create(['body' => 'Bom dia!', 'sent_at' => now()->subMinutes(10)]);
    $this->bruno->forceFill(['last_message_at' => now()->subMinutes(10)])->save();
    $this->brunoEntry = $this->bruno->conversation;
});

test('the AI prioritizes the unread conversations picked, of every channel, apart from the priority the user gave them', function () {
    Queue::fake([TriageChatConversations::class]);

    $screen = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->assertSeeHtml('data-chat-select-pick="'.$this->general->friendly_id.'"')
        ->call('triageConversations', [$this->ana->friendly_id, $this->brunoEntry->friendly_id, $this->general->friendly_id])
        ->assertSet('notice', 'The AI is prioritizing 2 conversations. The list sorts by it under “AI”.')
        ->assertSeeInIsland('data-chat-ai-pending="'.$this->ana->friendly_id.'"')
        ->assertSeeInIsland('data-chat-ai-pending="'.$this->brunoEntry->friendly_id.'"');

    // A WhatsApp chat keeps it on its target, which its entry copies.
    expect($this->bruno->fresh()->ai_triage_requested_at)->not->toBeNull()
        ->and($this->general->fresh()->ai_triage_requested_at)->toBeNull();
    Queue::assertPushedOn('chats', TriageChatConversations::class, fn (TriageChatConversations $job): bool => $job->conversationIds === [$this->ana->id, $this->brunoEntry->id]);

    ChatTriageAgent::fake([['conversations' => [
        ['id' => $this->ana->friendly_id, 'urgency' => 'urgent', 'summary' => 'A produção caiu depois do deploy.'],
        ['id' => $this->brunoEntry->friendly_id, 'urgency' => 'info', 'summary' => 'Só um bom dia.'],
    ]]])->preventStrayPrompts();
    Event::fake([ChatConversationsTriaged::class]);

    app()->call([new TriageChatConversations($this->owner, [$this->ana->id, $this->brunoEntry->id]), 'handle']);

    ChatTriageAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('O deploy quebrou a produção')
        && $prompt->contains('Bom dia!')
        && $prompt->contains('Channel: WhatsApp')
        && ! $prompt->contains('#general')
        && ! $prompt->contains('riority'));
    Event::assertDispatched(ChatConversationsTriaged::class, fn (ChatConversationsTriaged $event): bool => $event->userId === $this->owner->id && ! $event->failed);

    expect($this->ana->fresh())
        ->ai_urgency->toBe(1)
        ->ai_summary->toBe('A produção caiu depois do deploy.')
        ->ai_triage_requested_at->toBeNull()
        ->priority->toBe(5)
        ->and($this->brunoEntry->fresh())->ai_urgency->toBe(4)->priority->toBe(1)
        ->and($this->bruno->fresh())->ai_urgency->toBe(4)->ai_triage_requested_at->toBeNull();

    $screen->call('conversationsTriaged')
        ->assertSeeInIsland('data-chat-ai-urgency="'.$this->ana->friendly_id.'" data-urgency="urgent"')
        ->set('sort', ChatsPage::SORT_AI)
        ->assertSeeInIsland('data-chat-ai-summary="'.$this->ana->friendly_id.'"');

    expect($screen->instance()->conversations->pluck('name')->all())->toBe(['Ana Lima', 'Bruno', '#general']);
});

test('a conversation read forgets what the AI found, on WhatsApp too', function () {
    $triaged = ['ai_urgency' => 1, 'ai_summary' => 'Precisa de ajuda.', 'ai_triaged_at' => now(), 'ai_triaged_through' => now()->subHour()];
    $this->ana->update($triaged);
    $this->bruno->update($triaged);

    expect($this->brunoEntry->fresh()->ai_urgency)->toBe(1);

    Livewire::actingAs($this->owner)->test(ChatsPage::class)->call('openConversation', $this->ana->friendly_id);

    expect($this->ana->fresh())->ai_urgency->toBeNull()->ai_summary->toBeNull()->priority->toBe(5);

    // Read on the phone.
    $this->bruno->update(['unread_count' => 0]);

    expect($this->brunoEntry->fresh())->ai_urgency->toBeNull()->priority->toBe(1);
});

test('the AI is not asked without an AI set up, nor for too many conversations, nor for another user\'s', function () {
    Queue::fake([TriageChatConversations::class]);

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('triageConversations', ChatConversation::factory()->for($this->owner)->count(RequestChatTriage::MAX_CONVERSATIONS + 1)->create([
            'chat_provider_id' => ChatProvider::idFor(ChatProvider::SLACK),
            'unread_count' => 1,
        ])->pluck('friendly_id')->all())
        ->assertSet('error', 'The AI prioritizes up to 20 conversations at a time.');

    $stranger = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();

    expect(fn () => app(RequestChatTriage::class)($stranger, [$this->ana->friendly_id]))
        ->toThrow(ValidationException::class)
        ->and($this->ana->fresh()->ai_triage_requested_at)->toBeNull();

    AiProviderConnection::query()->delete();

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('triageConversations', [$this->ana->friendly_id])
        ->assertSet('error', 'No AI provider is set up. Ask the administrator.');

    Queue::assertNothingPushed();
});

test('a triage the AI could not do stops waiting, and the screen says so', function () {
    Event::fake([ChatConversationsTriaged::class]);
    $this->ana->update(['ai_triage_requested_at' => now()]);
    $this->bruno->update(['ai_triage_requested_at' => now()]);

    (new TriageChatConversations($this->owner, [$this->ana->id, $this->brunoEntry->id]))->failed(new RuntimeException('timeout'));

    expect($this->ana->fresh()->ai_triage_requested_at)->toBeNull()
        ->and($this->bruno->fresh()->ai_triage_requested_at)->toBeNull()
        ->and($this->brunoEntry->fresh()->ai_triage_requested_at)->toBeNull();
    Event::assertDispatched(ChatConversationsTriaged::class, fn (ChatConversationsTriaged $event): bool => $event->failed);

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('conversationsTriaged', ['failed' => true])
        ->assertSet('error', 'The AI could not prioritize the conversations. Try again in a moment.');
});

test('many conversations of every channel change at once, without announcing each WhatsApp chat', function () {
    $this->ana->update(['ai_urgency' => 2, 'ai_triaged_at' => now()]);
    $picked = [$this->ana->friendly_id, $this->brunoEntry->friendly_id, $this->general->friendly_id];

    $screen = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('bulkAction', 'clear_priority', $picked)
        ->assertSet('notice', 'Done in 2 conversations.');

    expect($this->ana->fresh()->priority)->toBeNull()
        ->and($this->ana->fresh()->ai_urgency)->toBe(2)
        ->and($this->bruno->fresh()->priority)->toBeNull();

    $screen->call('bulkAction', 'clear_ai', [$this->ana->friendly_id])
        ->call('bulkAction', 'read', $picked)
        ->assertSet('notice', 'Done in 2 conversations.')
        ->call('bulkAction', 'archive', $picked)
        ->assertSet('notice', 'Done in 3 conversations.')
        ->assertDontSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"');

    expect($this->ana->fresh())->ai_urgency->toBeNull()->unread_count->toBe(0)->archived_at->not->toBeNull()
        ->and($this->bruno->fresh())->unread_count->toBe(0)->archived_at->not->toBeNull()
        ->and($this->general->fresh()->archived_at)->not->toBeNull();
    Event::assertNotDispatched(WhatsappChatUpdated::class);

    $screen->call('bulkAction', 'mute', [$this->general->friendly_id, 'cnv_nope'])
        ->assertSet('notice', 'Done in 1 conversation.')
        ->call('bulkAction', 'explode', $picked)
        ->assertSet('error', 'The selected action is invalid.');

    expect($this->general->fresh()->isMuted())->toBeTrue()
        ->and(ApplyChatConversationsBulkAction::isRunning())->toBeFalse();
});
