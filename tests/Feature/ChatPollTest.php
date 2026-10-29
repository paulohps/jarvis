<?php

use App\Actions\Chats\SendChatScheduledMessage;
use App\Livewire\Chats\ChatsPage;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\ChatScheduledDelivery;
use App\Models\ChatScheduledMessage;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Fixtures\Slack\FakeSlackApi;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));
    // Slack's fake first: installed after it, it would leave Evolution's stray.
    FakeSlackApi::install();
    FakeEvolutionApi::install();

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS, Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $providers = collect([ChatProvider::WHATSAPP, ChatProvider::SLACK])->mapWithKeys(fn (string $slug): array => [ChatProvider::idFor($slug) => ['enabled_by_id' => $this->owner->id]])->all();
    $this->workspace->chatProviders()->sync($providers);
    $this->owner->memberships()->sole()->chatProviders()->sync($providers);
    $this->owner->forgetMemberships();

    $account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis']);
    $target = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net']);
    $target->forceFill(['last_message_at' => now()->subMinute()])->save();
    $this->ana = $target->conversation;

    $slack = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => 'acme']);
    $dm = SlackConversation::factory()->for($slack, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID]);
    $this->slack = ChatConversation::factory()->for($this->owner)->forSlack($dm)->direct()->create(['name' => 'Ana Lima', 'last_message_at' => now()->subDay()]);

    $this->sentPolls = fn () => collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/message/sendPoll/'))->map(fn (array $pair): Request => $pair[0])->values();
});

test('the inbox sends a poll to a whatsapp conversation', function () {
    $screen = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-poll-open')
        ->call('submitPoll', 'Pizza ou sushi?', ['Pizza', 'pizza'], false, false)
        ->assertHasErrors('pollOptions.1');

    expect(($this->sentPolls)())->toHaveCount(0);

    $screen->call('submitPoll', ' ', ['Pizza'], false, false)
        ->assertHasErrors(['pollQuestion', 'pollOptions']);

    $screen->set('showPoll', true)
        ->call('submitPoll', 'Pizza ou sushi?', ['Pizza', ' ', 'Sushi'], true, false)
        ->assertHasNoErrors()
        ->assertSet('showPoll', false)
        ->assertSet('notice', 'Poll sent.')
        ->assertDispatched('chat-sent');

    expect(($this->sentPolls)()->sole()->data())->toBe(['number' => '5511911110000@s.whatsapp.net', 'name' => 'Pizza ou sushi?', 'selectableCount' => 0, 'values' => ['Pizza', 'Sushi']]);
});

test('the inbox schedules a poll, which goes out as a poll at its time', function () {
    $screen = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->assertSet('pollRecurrence.starts_at', '2026-10-15T09:00')
        ->set('pollRecurrence.starts_at', '2026-10-13T09:00')
        ->call('submitPoll', 'Churrasco sábado?', ['Sim', 'Não'], false, true)
        ->assertHasErrors('pollRecurrence.starts_at');

    expect(ChatScheduledMessage::query()->count())->toBe(0);

    $screen->set('pollRecurrence.starts_at', '2026-10-15T18:00')
        ->call('submitPoll', 'Churrasco sábado?', ['Sim', 'Não'], false, true)
        ->assertHasNoErrors()
        ->assertSet('notice', 'Poll scheduled.')
        ->assertSet('pollRecurrence.starts_at', '2026-10-15T09:00')
        ->assertSee('📊 Churrasco sábado?')
        ->assertNotDispatched('chat-sent');

    $scheduled = ChatScheduledMessage::query()->sole();

    expect($scheduled->poll)->toBe(['name' => 'Churrasco sábado?', 'options' => ['Sim', 'Não'], 'selectable_count' => 1])
        ->and($scheduled->body)->toBe('Churrasco sábado?')
        ->and($scheduled->sendsPoll())->toBeTrue()
        ->and(($this->sentPolls)())->toHaveCount(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 18:01', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();
    // The same occurrence again sends nothing.
    app(SendChatScheduledMessage::class)($scheduled->fresh(), CarbonImmutable::parse('2026-10-15 18:00', 'UTC'));

    expect(($this->sentPolls)()->sole()->data())->toBe(['number' => '5511911110000@s.whatsapp.net', 'name' => 'Churrasco sábado?', 'selectableCount' => 1, 'values' => ['Sim', 'Não']])
        ->and(ChatScheduledDelivery::query()->sole()->status)->toBe(ChatScheduledDelivery::SENT);
});

test('a conversation without polls has no poll, and refuses one', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->slack->friendly_id])
        ->assertDontSeeHtml('data-chat-poll-open')
        ->call('submitPoll', 'Lunch?', ['Yes', 'No'], false, false)
        ->assertHasErrors('poll')
        ->call('submitPoll', 'Lunch?', ['Yes', 'No'], false, true)
        ->assertHasErrors('poll');

    expect(ChatScheduledMessage::query()->count())->toBe(0)
        ->and(FakeSlackApi::called('chat.postMessage'))->toBeFalse();
});

test('the api schedules a poll to a whatsapp conversation and tells which have polls', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.chats.conversations.show', $this->ana->friendly_id))->assertJsonPath('data.capabilities.polls', true);

    $this->postJson(route('api.chats.conversations.schedule', $this->ana->friendly_id), [
        'poll' => ['question' => 'Lunch?', 'options' => ['Yes', 'No'], 'multiple' => true],
        'send_at' => '2026-10-15 10:00',
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Lunch?')
        ->assertJsonPath('data.poll', ['question' => 'Lunch?', 'options' => ['Yes', 'No'], 'multiple' => true]);

    $this->postJson(route('api.chats.conversations.schedule', $this->ana->friendly_id), [
        'poll' => ['question' => 'Lunch?', 'options' => ['Yes']],
        'ai_prompt' => 'Ask about lunch',
        'send_at' => '2026-10-15 10:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('poll');

    $this->postJson(route('api.chats.conversations.schedule', $this->slack->friendly_id), [
        'poll' => ['question' => 'Lunch?', 'options' => ['Yes', 'No']],
        'send_at' => '2026-10-15 10:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('poll');
});

test('the list goes again only when it changed', function () {
    $this->ana->forceFill(['unread_count' => 2])->save();

    $screen = Livewire::actingAs($this->owner)->test(ChatsPage::class)
        ->assertSeeHtml('data-chat-item="'.$this->ana->friendly_id.'"');

    // Opening a conversation already read leaves the list as it is.
    $screen->call('openConversation', $this->slack->friendly_id);
    expect($screen->effects['islandFragments'] ?? [])->toBe([]);

    // Opening an unread one reads it: the list goes again, without its badge.
    $screen->call('openConversation', $this->ana->friendly_id)
        ->assertSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"')
        ->assertDontSeeInIsland('data-chat-unread="'.$this->ana->friendly_id.'"');

    $screen->set('text', 'oi');
    expect($screen->effects['islandFragments'] ?? [])->toBe([]);

    $screen->set('search', 'Lima')
        ->assertSeeInIsland('data-chat-item="'.$this->slack->friendly_id.'"')
        ->assertDontSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"');

    expect(count($screen->effects['islandFragments']))->toBe(1);
});
