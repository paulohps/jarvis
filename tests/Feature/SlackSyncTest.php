<?php

use App\Actions\Slack\EnsureSlackConversation;
use App\Actions\Slack\RecordSlackMessages;
use App\Events\ChatAccountSynced;
use App\Jobs\LoadSlackConversationHistory;
use App\Jobs\SyncSlackAccount;
use App\Models\ChatConversation;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\User;
use App\Services\Slack\SlackUserDirectory;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Event::fake([ChatAccountSynced::class]);
    FakeSlackApi::install();

    $this->owner = User::factory()->withModules([Module::CHATS])->create(['timezone' => 'UTC']);
    $this->account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => null, 'synced_at' => null]);
});

test('syncing an account brings its people and conversations, with entries on the chat screen', function () {
    Queue::fake([LoadSlackConversationHistory::class]);
    $gone = SlackConversation::factory()->for($this->account, 'account')->create(['channel_id' => 'COLD', 'name' => 'old']);

    (new SyncSlackAccount($this->account))->handle(app(EnsureSlackConversation::class), app(SlackUserDirectory::class));

    $account = $this->account->fresh();
    expect($account->team_domain)->toBe('acme')
        ->and($account->synced_at)->not->toBeNull()
        ->and($account->members()->count())->toBe(3)
        ->and($account->members()->where('slack_user_id', FakeSlackApi::ANA_ID)->sole()->displayName())->toBe('Ana Lima');

    $channel = SlackConversation::query()->where('channel_id', FakeSlackApi::CHANNEL_ID)->sole();
    $dm = SlackConversation::query()->where('channel_id', FakeSlackApi::DM_ID)->sole();

    expect($channel->kind)->toBe(SlackConversation::KIND_PUBLIC)
        ->and($channel->topic)->toBe('Company wide')
        ->and($channel->conversation->name)->toBe('#general')
        ->and($channel->conversation->kind)->toBe(ChatConversation::KIND_CHANNEL)
        ->and($dm->kind)->toBe(SlackConversation::KIND_IM)
        ->and($dm->counterpart_user_id)->toBe(FakeSlackApi::ANA_ID)
        ->and($dm->conversation->name)->toBe('Ana Lima')
        ->and($dm->conversation->kind)->toBe(ChatConversation::KIND_DIRECT)
        ->and($dm->conversation->picture_url)->toBe('https://avatars.slack-edge.com/'.FakeSlackApi::ANA_ID.'_72.png')
        ->and($dm->conversation->user_id)->toBe($this->owner->id)
        ->and($dm->conversation->provider->slug)->toBe('slack')
        ->and($gone->fresh()->is_member)->toBeFalse();

    // DMs load first, the old one is skipped.
    Queue::assertPushed(LoadSlackConversationHistory::class, 2);
    Event::assertDispatched(ChatAccountSynced::class);
});

test('a token slack rejects marks the account failed instead of retrying', function () {
    Queue::fake();
    FakeSlackApi::install(['auth.test' => ['ok' => false, 'error' => 'token_revoked']]);

    (new SyncSlackAccount($this->account))->handle(app(EnsureSlackConversation::class), app(SlackUserDirectory::class));

    expect($this->account->fresh()->hasFailed())->toBeTrue()
        ->and($this->account->fresh()->last_error)->toBe('token_revoked');
    Queue::assertNotPushed(LoadSlackConversationHistory::class);
});

test('loading the history records the latest messages and a DM unread count from slack', function () {
    $dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID, 'history_loaded_at' => null]);
    $entry = ChatConversation::factory()->for($this->owner)->forSlack($dm)->direct()->create(['name' => 'Ana Lima']);

    (new LoadSlackConversationHistory($dm))->handle(app(RecordSlackMessages::class));

    expect(SlackMessage::query()->where('slack_conversation_id', $dm->id)->count())->toBe(3)
        ->and($dm->fresh()->history_loaded_at)->not->toBeNull()
        ->and($dm->fresh()->history_exhausted_at)->not->toBeNull()
        ->and($dm->fresh()->last_read)->toBe('1700000000.000100')
        ->and($entry->fresh()->unread_count)->toBe(2)
        ->and($entry->fresh()->last_message['text'])->toBe('Hi @Jane Doe, can you review the PR?')
        ->and($entry->fresh()->last_message_at->getTimestamp())->toBe(1700000300);

    $reacted = SlackMessage::query()->where('ts', '1700000100.000100')->sole();
    expect($reacted->reactions)->toBe([['name' => 'thumbsup', 'users' => [FakeSlackApi::USER_ID]]]);
});

test('a rate limit releases the history job for as long as slack asks', function () {
    $dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID, 'history_loaded_at' => null]);
    ChatConversation::factory()->for($this->owner)->forSlack($dm)->direct()->create();
    FakeSlackApi::install(['conversations.history' => Http::response(['ok' => false, 'error' => 'ratelimited'], 429, ['Retry-After' => '45'])]);

    $job = Mockery::mock(LoadSlackConversationHistory::class.'[release]', [$dm]);
    $job->shouldReceive('release')->once()->with(45);

    $job->handle(app(RecordSlackMessages::class));

    expect($dm->fresh()->history_loaded_at)->toBeNull()
        ->and(SlackMessage::query()->count())->toBe(0);
});
