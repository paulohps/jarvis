<?php

use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
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
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Event::fake([ChatConversationUpdated::class]);
    FakeSlackApi::install();

    $workspace = Workspace::factory()->create();
    $this->owner = User::factory()->inWorkspace($workspace, [Module::CHATS])->create(['timezone' => 'UTC']);
    $slack = ChatProvider::idFor(ChatProvider::SLACK);
    $workspace->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->memberships()->sole()->chatProviders()->sync([$slack => ['enabled_by_id' => $this->owner->id]]);
    $this->owner->forgetMemberships();

    $this->account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_name' => 'Acme']);
    SlackUser::factory()->for($this->account, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    $this->dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID, 'history_exhausted_at' => now()]);
    $this->ana = ChatConversation::factory()->for($this->owner)->forSlack($this->dm)->direct()->create(['name' => 'Ana Lima', 'unread_count' => 1, 'last_message_at' => now()->subMinute()]);
    $this->message = SlackMessage::factory()->for($this->dm, 'conversation')->create(['ts' => '1700000100.000100', 'slack_user_id' => FakeSlackApi::ANA_ID, 'text' => 'Hi there', 'sent_at' => SlackMessage::instantOf('1700000100.000100')]);

    Sanctum::actingAs($this->owner);
});

test('the providers endpoint lists the channels with their capabilities and accounts', function () {
    $this->getJson('/api/v1/chats/providers')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'slack')
        ->assertJsonPath('data.0.capabilities.threads', true)
        ->assertJsonPath('data.0.accounts.0.label', 'Acme')
        ->assertJsonPath('data.0.accounts.0.is_usable', true);
});

test('the conversations are listed, shown with capabilities and updated', function () {
    $this->getJson('/api/v1/chats/conversations')
        ->assertOk()
        ->assertJsonPath('data.0.friendly_id', $this->ana->friendly_id)
        ->assertJsonPath('data.0.provider', 'slack')
        ->assertJsonPath('data.0.unread_count', 1)
        ->assertJsonMissingPath('data.0.capabilities');

    $this->getJson("/api/v1/chats/conversations/{$this->ana->friendly_id}")
        ->assertOk()
        ->assertJsonPath('data.capabilities.send', true)
        ->assertJsonPath('data.external_url', 'https://'.$this->account->team_domain.'.slack.com/archives/'.FakeSlackApi::DM_ID)
        ->assertJsonPath('scheduled', []);

    $tag = ChatTag::factory()->for($this->owner)->create(['name' => 'Work']);

    $this->patchJson("/api/v1/chats/conversations/{$this->ana->friendly_id}", ['pinned' => true, 'priority' => 1, 'nickname' => 'Ana (work)', 'mute' => '8h', 'tags' => [$tag->friendly_id], 'unread' => false])
        ->assertOk()
        ->assertJsonPath('data.display_name', 'Ana (work)')
        ->assertJsonPath('data.priority', 1)
        ->assertJsonPath('data.unread_count', 0)
        ->assertJsonPath('data.tags.0.name', 'Work');

    expect($this->ana->fresh()->isPinned())->toBeTrue()
        ->and($this->ana->fresh()->isMuted())->toBeTrue();

    $this->patchJson("/api/v1/chats/conversations/{$this->ana->friendly_id}", ['priority' => 9])->assertUnprocessable();
});

test('messages are read, sent, reacted to, edited and deleted through the channel', function () {
    $this->getJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->message->friendly_id)
        ->assertJsonPath('data.0.text', 'Hi there')
        ->assertJsonPath('data.0.sender.name', 'Ana')
        ->assertJsonPath('meta.has_older', false);

    $sent = $this->postJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages", ['text' => 'Hello'])
        ->assertCreated()
        ->assertJsonPath('data.from_me', true)
        ->json('data');

    expect(FakeSlackApi::called('chat.postMessage', ['text' => 'Hello']))->toBeTrue();

    $this->postJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages", ['text' => ''])->assertUnprocessable();

    $this->postJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages/{$this->message->friendly_id}/reaction", ['emoji' => '❤️'])
        ->assertOk()
        ->assertJsonPath('data.reactions.0.emoji', '❤️')
        ->assertJsonPath('data.reactions.0.mine', true);

    $this->postJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages/{$this->message->friendly_id}/reaction", ['emoji' => '🫥'])->assertUnprocessable();

    $this->patchJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages/{$sent['id']}", ['text' => 'edited text'])
        ->assertOk()
        ->assertJsonPath('data.text', 'edited text');

    $this->patchJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages/{$this->message->friendly_id}", ['text' => 'nope'])->assertUnprocessable();

    $this->deleteJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/messages/{$sent['id']}")->assertNoContent();
});

test('tags and contacts are managed for the user only', function () {
    $tag = $this->postJson('/api/v1/chats/tags', ['name' => 'Work', 'color' => 'pink'])->assertCreated()->json('data');

    $this->postJson('/api/v1/chats/tags', ['name' => 'work', 'color' => 'red'])->assertUnprocessable();
    $this->patchJson("/api/v1/chats/tags/{$tag['friendly_id']}", ['name' => 'Office'])->assertOk()->assertJsonPath('data.name', 'Office');
    $this->getJson('/api/v1/chats/tags')->assertOk()->assertJsonCount(1, 'data');

    $other = ChatConversation::factory()->for($this->owner)->create(['name' => '#general']);
    $this->postJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/contact", ['conversation' => $other->friendly_id])
        ->assertOk()
        ->assertJsonPath('data.contact.name', '#general');

    $contact = $this->getJson('/api/v1/chats/contacts')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($contact['conversation_ids'])->toHaveCount(2);

    $this->patchJson("/api/v1/chats/contacts/{$contact['friendly_id']}", ['name' => 'Jessé', 'notes' => 'Works with me'])->assertOk()->assertJsonPath('data.name', 'Jessé');
    $this->deleteJson("/api/v1/chats/conversations/{$this->ana->friendly_id}/contact")->assertOk()->assertJsonPath('data.contact', null);

    $this->deleteJson("/api/v1/chats/tags/{$tag['friendly_id']}")->assertNoContent();

    $stranger = User::factory()->withModules([Module::CHATS])->create();
    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/chats/conversations/{$this->ana->friendly_id}")->assertNotFound();
    $this->getJson('/api/v1/chats/conversations')->assertOk()->assertJsonCount(0, 'data');
});

test('the endpoints need the chats module', function () {
    Sanctum::actingAs(User::factory()->withModules([Module::SHOPPING])->create());

    $this->getJson('/api/v1/chats/providers')->assertForbidden();
    $this->getJson('/api/v1/chats/conversations')->assertForbidden();
});
