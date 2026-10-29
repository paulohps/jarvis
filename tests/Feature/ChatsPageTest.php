<?php

use App\Ai\Agents\ChatReplyAgent;
use App\Events\ChatConversationUpdated;
use App\Livewire\Chats\ChatsPage;
use App\Models\AiProviderConnection;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\ChatScheduledMessage;
use App\Models\ChatTag;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Event::fake([ChatConversationUpdated::class]);
    FakeSlackApi::install();

    $this->workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($this->workspace, [Module::CHATS, Module::REMINDERS])->create(['timezone' => 'UTC']);
    $slack = ChatProvider::idFor(ChatProvider::SLACK);
    $this->workspace->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->memberships()->sole()->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->forgetMemberships();

    $this->account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => 'acme']);
    SlackUser::factory()->for($this->account, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    $this->dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID]);
    $this->ana = ChatConversation::factory()->for($this->owner)->forSlack($this->dm)->direct()->create(['name' => 'Ana Lima', 'unread_count' => 2, 'last_message_at' => now()->subMinute(), 'last_message' => ['text' => 'Hi there', 'from_me' => false, 'sender' => 'Ana Lima', 'kind' => 'text']]);
    $general = SlackConversation::factory()->for($this->account, 'account')->create(['channel_id' => FakeSlackApi::CHANNEL_ID, 'name' => 'general']);
    $this->general = ChatConversation::factory()->for($this->owner)->forSlack($general)->create(['name' => '#general', 'last_message_at' => now()->subHour()]);

    $this->message = SlackMessage::factory()->for($this->dm, 'conversation')->create(['ts' => '1700000100.000100', 'slack_user_id' => FakeSlackApi::ANA_ID, 'text' => 'Hi there', 'sent_at' => SlackMessage::instantOf('1700000100.000100')]);
});

test('the chat screen lists the conversations of every channel with their mark, latest first', function () {
    $this->actingAs($this->owner)->get(route('chats.chat'))
        ->assertOk()
        ->assertSeeLivewire(ChatsPage::class)
        ->assertSeeInOrder(["data-chat-item=\"{$this->ana->friendly_id}\"", "data-chat-item=\"{$this->general->friendly_id}\""], false)
        ->assertSee('data-chat-item-mark="slack"', false)
        ->assertSee("data-chat-unread=\"{$this->ana->friendly_id}\"", false)
        ->assertSee('Hi there');
});

test('opening a conversation shows its messages and marks it read', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('openConversation', $this->ana->friendly_id)
        ->assertSet('conversationId', $this->ana->friendly_id)
        ->assertSee("data-chat-message=\"{$this->message->friendly_id}\"", false)
        ->assertSee('Hi there')
        ->assertSeeHtml('data-chat-composer');

    expect($this->ana->fresh()->unread_count)->toBe(0)
        ->and(FakeSlackApi::called('conversations.mark', ['channel' => FakeSlackApi::DM_ID, 'ts' => '1700000100.000100']))->toBeTrue()
        ->and($this->dm->fresh()->last_read)->toBe('1700000100.000100');
});

test('a message typed goes to the channel and shows in the conversation', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->set('text', 'Hello @ana')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('text', '')
        ->assertSee('posted text');

    expect(FakeSlackApi::called('chat.postMessage', ['text' => 'Hello <@'.FakeSlackApi::ANA_ID.'>']))->toBeTrue();

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->set('text', '   ')
        ->call('send')
        ->assertHasErrors(['text']);
});

test('a read-only conversation offers no composer and says why', function () {
    $this->dm->forceFill(['is_member' => false])->save();

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-read-only')
        ->assertDontSeeHtml('data-chat-composer');
});

test('pin, priority, nickname, archive, mute and unread are kept per conversation', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->general->friendly_id])
        ->call('togglePin', $this->general->friendly_id)
        ->call('editPriority')
        ->set('priority', '2')
        ->call('savePriority')
        ->assertHasNoErrors()
        ->call('editNickname')
        ->set('nickname', 'Work talk')
        ->call('saveNickname')
        ->assertHasNoErrors()
        ->call('mute', $this->general->friendly_id, '1w');

    $general = $this->general->fresh();
    expect($general->isPinned())->toBeTrue()
        ->and($general->priority)->toBe(2)
        ->and($general->displayName())->toBe('Work talk')
        ->and($general->muted_until->toDateTimeString())->toBe('2026-10-16 12:00:00');

    $html = implode('', $component->effects['islandFragments']);
    expect(strpos($html, "data-chat-item=\"{$this->general->friendly_id}\""))->toBeLessThan(strpos($html, "data-chat-item=\"{$this->ana->friendly_id}\""));

    $component->call('archive', $this->general->friendly_id, true)
        ->assertSet('conversationId', null)
        ->assertDontSeeInIsland("data-chat-item=\"{$this->general->friendly_id}\"")
        ->set('showArchived', true)
        ->assertSeeInIsland("data-chat-item=\"{$this->general->friendly_id}\"");

    $component->call('markUnread', $this->ana->friendly_id, true);
    expect($this->ana->fresh()->marked_unread)->toBeTrue();
});

test('tags are created, put on a conversation and filter the list', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('editTags')
        ->set('tagName', 'Work')
        ->set('tagColor', 'pink')
        ->call('saveTag')
        ->assertHasNoErrors();

    $tag = ChatTag::query()->where('user_id', $this->owner->id)->sole();
    expect($tag->name)->toBe('Work')
        ->and($component->get('conversationTags'))->toBe([$tag->friendly_id]);

    $component->call('saveConversationTags')->assertHasNoErrors();
    expect($this->ana->fresh()->tags->pluck('name')->all())->toBe(['Work']);

    $component->call('filterByTag', $tag->friendly_id)
        ->assertSeeInIsland("data-chat-item=\"{$this->ana->friendly_id}\"")
        ->assertDontSeeInIsland("data-chat-item=\"{$this->general->friendly_id}\"");

    $component->set('tagName', 'work')->set('tagColor', 'red')->call('saveTag')->assertHasErrors(['name']);
});

test('a conversation is linked to the same person on another channel under one contact', function () {
    $component = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('openContact')
        ->call('linkToConversation', $this->general->friendly_id)
        ->assertHasNoErrors()
        ->assertSet('showContact', false);

    $contact = ChatContact::query()->where('user_id', $this->owner->id)->sole();
    expect($contact->name)->toBe('#general')
        ->and($this->ana->fresh()->chat_contact_id)->toBe($contact->id)
        ->and($this->general->fresh()->chat_contact_id)->toBe($contact->id);

    $component->assertSeeInIsland("data-chat-item-contact=\"{$contact->friendly_id}\"");

    $component->call('unlinkContact');
    expect($this->ana->fresh()->chat_contact_id)->toBeNull()
        ->and($contact->fresh())->not->toBeNull();

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->general->friendly_id])
        ->call('unlinkContact');
    expect(ChatContact::query()->count())->toBe(0);

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('openContact')
        ->set('contactName', 'Jessé')
        ->call('createContact')
        ->assertHasNoErrors();
    expect($this->ana->fresh()->contact->name)->toBe('Jessé');
});

test('a reminder and a scheduled message are made from the conversation', function () {
    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('startReminder', $this->message->friendly_id)
        ->set('reminderTitle', 'Answer Ana')
        ->set('reminderDueAt', '2026-10-10T09:00')
        ->call('createReminder')
        ->assertHasNoErrors()
        ->assertSee('data-chat-reminder-item=', false);

    $reminder = $this->ana->reminders()->sole();
    expect($reminder->title)->toBe('Answer Ana')
        ->and($reminder->note)->toContain('Slack: Ana Lima', 'Ana: “Hi there”')
        ->and($reminder->pivot->message_id)->toBe($this->message->friendly_id);

    Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('startSchedule')
        ->set('scheduleBody', 'Later!')
        ->set('scheduleRecurrence.starts_at', '2026-10-09T15:30')
        ->call('schedule')
        ->assertHasNoErrors()
        ->assertSee('Later!');

    $scheduled = ChatScheduledMessage::query()->sole();
    expect($scheduled->nextSendAt()->toDateTimeString())->toBe('2026-10-09 15:30:00')
        ->and($scheduled->isPending())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-09 15:31', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();

    expect($scheduled->fresh()->sent_at)->not->toBeNull()
        ->and(FakeSlackApi::called('chat.postMessage', ['text' => 'Later!']))->toBeTrue();
});

test('reactions, threads and edits use what the channel supports', function () {
    $mine = SlackMessage::factory()->for($this->dm, 'conversation')->create(['ts' => '1700000200.000100', 'slack_user_id' => FakeSlackApi::USER_ID, 'text' => 'Sure', 'sent_at' => SlackMessage::instantOf('1700000200.000100'), 'thread_ts' => '1700000200.000100', 'reply_count' => 0]);

    $component = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->call('react', $this->message->friendly_id, '👍', true)
        ->assertSee('data-chat-reaction="👍"', false);

    expect($this->message->fresh()->reactions)->toBe([['name' => '+1', 'users' => [FakeSlackApi::USER_ID]]]);

    $component->call('openThread', $mine->friendly_id)
        ->assertSet('threadId', $mine->friendly_id)
        ->assertSeeHtml('data-chat-thread="'.$mine->friendly_id.'"')
        ->set('threadText', 'in thread')
        ->call('sendInThread')
        ->assertHasNoErrors();
    expect(FakeSlackApi::called('chat.postMessage', ['thread_ts' => '1700000200.000100', 'text' => 'in thread']))->toBeTrue();

    $component->call('startEdit', $mine->friendly_id)
        ->assertSet('editText', 'Sure')
        ->set('editText', 'edited text')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSet('showEdit', false);
    expect($mine->fresh()->text)->toBe('edited text');

    $component->call('deleteMessage', $mine->friendly_id);
    expect($mine->fresh()->isDeleted())->toBeTrue();
});

test('someone else never sees or touches the conversation', function () {
    $other = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    $slack = ChatProvider::idFor(ChatProvider::SLACK);
    $other->memberships()->sole()->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);

    Livewire::actingAs($other)
        ->test(ChatsPage::class, ['conversationId' => $this->ana->friendly_id])
        ->assertDontSee('Hi there')
        ->assertDontSee("data-chat-item=\"{$this->ana->friendly_id}\"", false)
        ->call('togglePin', $this->ana->friendly_id)
        ->assertNotFound();

    $noChannel = User::factory()->inWorkspace($this->workspace, [Module::CHATS])->create();
    Livewire::actingAs($noChannel)
        ->test(ChatsPage::class)
        ->assertSeeHtml('data-chat-no-providers');
});

test('a draft the model cut short (out of tokens, its reasoning counted) is not shown, and the owner is told', function () {
    AiProviderConnection::factory()->active()->create();
    $answer = fn (string $text, string $status): array => [
        'id' => 'resp_1',
        'object' => 'response',
        'status' => $status,
        'model' => 'gpt-6-luna',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
            ['type' => 'message', 'id' => 'msg_1', 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]],
        ],
        'usage' => ['input_tokens' => 3000, 'output_tokens' => 8000, 'output_tokens_details' => ['reasoning_tokens' => 7900]],
    ];
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push($answer('Hi Ana, about the', 'incomplete'))
        ->push($answer('Hi Ana, about the deploy: it is done.', 'completed'))]);

    $page = Livewire::actingAs($this->owner)
        ->test(ChatsPage::class)
        ->call('openConversation', $this->ana->friendly_id)
        ->call('startDraft')
        ->set('draftInstruction', 'tell her the deploy is done')
        ->call('writeDraft')
        ->assertSet('error', __('The message came out too long and was cut short. Ask for a shorter one, or try again.'))
        ->assertSet('draft', '');

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'api.openai.com') && $request['max_output_tokens'] === ChatReplyAgent::MAX_TOKENS);

    $page->call('startDraft')->set('draftInstruction', 'tell her the deploy is done')->call('writeDraft')
        ->assertSet('draft', 'Hi Ana, about the deploy: it is done.');
});
