<?php

use App\Actions\Slack\HandleSlackEvent;
use App\Events\ChatAccountSynced;
use App\Events\ChatConversationUpdated;
use App\Events\NotificationCreated;
use App\Models\ChatConversation;
use App\Models\Device;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Services\Notifications\Types\ChatMessageReceived;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\Push\FakeFcm;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->artisan('notifications:provision')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Notification::fake();
    Event::fake([ChatConversationUpdated::class, ChatAccountSynced::class]);
    FakeFcm::install();
    FakeSlackApi::install();

    $this->owner = User::factory()->withModules([Module::CHATS])->create(['timezone' => 'UTC']);
    Device::factory()->for($this->owner)->create(['push_token' => 'phone-token']);
    $this->account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID]);
    SlackUser::factory()->for($this->account, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    $this->dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID]);
    $this->entry = ChatConversation::factory()->for($this->owner)->forSlack($this->dm)->direct()->create(['name' => 'Ana']);

    $this->callback = fn (array $event, array $extra = []): array => ['type' => 'event_callback', 'event_id' => 'Ev'.uniqid(), 'team_id' => FakeSlackApi::TEAM_ID, 'event' => $event, ...$extra];
    $this->handle = app(HandleSlackEvent::class);
});

test('a message event records the message, counts it unread, announces it and pushes it', function () {
    $handled = ($this->handle)(($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Hello <@'.FakeSlackApi::USER_ID.'> :wave:', ['channel' => FakeSlackApi::DM_ID, 'channel_type' => 'im'])));

    expect($handled)->toBeTrue();

    $message = SlackMessage::query()->where('ts', '1700001000.000100')->sole();
    expect($message->slack_conversation_id)->toBe($this->dm->id)
        ->and($message->friendly_id)->toStartWith('slm_')
        ->and($message->sent_at->toDateTimeString())->toBe('2023-11-14 22:30:00');

    $entry = $this->entry->fresh();
    expect($entry->unread_count)->toBe(1)
        ->and($entry->last_message_at->toDateTimeString())->toBe('2023-11-14 22:30:00')
        ->and($entry->last_message['text'])->toBe('Hello @Jane Doe 👋')
        ->and($entry->last_message['from_me'])->toBeFalse();

    Event::assertDispatched(ChatConversationUpdated::class, fn (ChatConversationUpdated $event): bool => $event->userId === $this->owner->id
        && $event->conversationId === $entry->friendly_id
        && $event->messageId === $message->friendly_id
        && $event->broadcastOn()[0]->name === 'private-'.NotificationCreated::channelFor($this->owner->id));

    Notification::assertSentTo($this->owner, ChatMessageReceived::class);
});

test('the account user answering resets the unread count and the same event twice counts once', function () {
    $incoming = ($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Hello', ['channel' => FakeSlackApi::DM_ID]));
    ($this->handle)($incoming);
    ($this->handle)($incoming);

    expect($this->entry->fresh()->unread_count)->toBe(1)
        ->and(SlackMessage::query()->count())->toBe(1);

    ($this->handle)(($this->callback)(FakeSlackApi::message('1700001100.000100', FakeSlackApi::USER_ID, 'Hi back', ['channel' => FakeSlackApi::DM_ID])));

    expect($this->entry->fresh()->unread_count)->toBe(0)
        ->and($this->entry->fresh()->last_message['from_me'])->toBeTrue();
    Notification::assertSentToTimes($this->owner, ChatMessageReceived::class, 1);
});

test('a muted conversation gets no push', function () {
    $this->entry->forceFill(['muted_until' => now()->addHours(8)])->save();

    ($this->handle)(($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Hello', ['channel' => FakeSlackApi::DM_ID])));

    expect($this->entry->fresh()->unread_count)->toBe(1);
    Notification::assertNothingSent();
});

test('edits, deletions and reactions update the recorded message', function () {
    ($this->handle)(($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Helo', ['channel' => FakeSlackApi::DM_ID])));

    ($this->handle)(($this->callback)([
        'type' => 'message', 'subtype' => 'message_changed', 'channel' => FakeSlackApi::DM_ID,
        'message' => FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Hello', ['edited' => ['user' => FakeSlackApi::ANA_ID, 'ts' => '1700001050.000000']]),
        'previous_message' => FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Helo'),
    ]));

    $message = SlackMessage::query()->where('ts', '1700001000.000100')->sole();
    expect($message->text)->toBe('Hello')
        ->and($message->edited_at)->not->toBeNull()
        ->and($this->entry->fresh()->last_message['text'])->toBe('Hello');

    ($this->handle)(($this->callback)(['type' => 'reaction_added', 'user' => FakeSlackApi::USER_ID, 'reaction' => 'heart', 'item' => ['type' => 'message', 'channel' => FakeSlackApi::DM_ID, 'ts' => '1700001000.000100']]));
    expect($message->fresh()->reactions)->toBe([['name' => 'heart', 'users' => [FakeSlackApi::USER_ID]]]);

    ($this->handle)(($this->callback)(['type' => 'reaction_removed', 'user' => FakeSlackApi::USER_ID, 'reaction' => 'heart', 'item' => ['type' => 'message', 'channel' => FakeSlackApi::DM_ID, 'ts' => '1700001000.000100']]));
    expect($message->fresh()->reactions)->toBe([]);

    ($this->handle)(($this->callback)(['type' => 'message', 'subtype' => 'message_deleted', 'channel' => FakeSlackApi::DM_ID, 'deleted_ts' => '1700001000.000100', 'ts' => '1700001100.000000']));
    expect($message->fresh()->deleted_at)->not->toBeNull()
        ->and($this->entry->fresh()->last_message['kind'])->toBe('deleted');
});

test('a message in a conversation not known yet is fetched from slack and kept when the user is in it', function () {
    FakeSlackApi::install(['conversations.info' => ['ok' => true, 'channel' => ['id' => 'C0000000099', 'name' => 'random', 'is_channel' => true, 'is_member' => true, 'is_archived' => false]]]);

    ($this->handle)(($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'New here', ['channel' => 'C0000000099'])));

    $conversation = SlackConversation::query()->where('channel_id', 'C0000000099')->sole();
    expect($conversation->kind)->toBe(SlackConversation::KIND_PUBLIC)
        ->and($conversation->conversation->name)->toBe('#random')
        ->and($conversation->conversation->kind)->toBe(ChatConversation::KIND_CHANNEL)
        ->and($conversation->messages()->count())->toBe(1);

    // One the user is not in is left alone.
    FakeSlackApi::install(['conversations.info' => ['ok' => true, 'channel' => ['id' => 'C0000000098', 'name' => 'secret', 'is_channel' => true, 'is_member' => false]]]);
    expect(($this->handle)(($this->callback)(FakeSlackApi::message('1700001000.000100', FakeSlackApi::ANA_ID, 'Not yours', ['channel' => 'C0000000098']))))->toBeFalse()
        ->and(SlackConversation::query()->where('channel_id', 'C0000000098')->exists())->toBeFalse();
});

test('channel archive, leaving and renaming follow the conversation; revoked tokens fail the account', function () {
    $channel = SlackConversation::factory()->for($this->account, 'account')->create(['channel_id' => FakeSlackApi::CHANNEL_ID, 'name' => 'general']);
    $entry = ChatConversation::factory()->for($this->owner)->forSlack($channel)->create(['name' => '#general']);

    ($this->handle)(($this->callback)(['type' => 'channel_rename', 'channel' => ['id' => FakeSlackApi::CHANNEL_ID, 'name' => 'announcements', 'created' => 1]]));
    expect($channel->fresh()->name)->toBe('announcements')
        ->and($entry->fresh()->name)->toBe('#announcements');

    ($this->handle)(($this->callback)(['type' => 'channel_archive', 'channel' => FakeSlackApi::CHANNEL_ID, 'user' => FakeSlackApi::ANA_ID]));
    expect($channel->fresh()->is_archived)->toBeTrue()
        ->and($entry->fresh()->archived_at)->not->toBeNull();

    ($this->handle)(($this->callback)(['type' => 'member_left_channel', 'channel' => FakeSlackApi::CHANNEL_ID, 'user' => FakeSlackApi::USER_ID, 'channel_type' => 'C']));
    expect($channel->fresh()->is_member)->toBeFalse();

    ($this->handle)(($this->callback)(['type' => 'tokens_revoked', 'tokens' => ['oauth' => [FakeSlackApi::USER_ID], 'bot' => []]]));
    expect($this->account->fresh()->hasFailed())->toBeTrue()
        ->and($this->account->fresh()->last_error)->toBe('token_revoked');
    Event::assertDispatched(ChatAccountSynced::class, fn (ChatAccountSynced $event): bool => $event->accountId === $this->account->friendly_id);
});

test('events of a workspace nobody connected are ignored', function () {
    expect(($this->handle)(['type' => 'event_callback', 'team_id' => 'TUNKNOWN', 'event' => FakeSlackApi::message('1.1', FakeSlackApi::ANA_ID, 'x', ['channel' => FakeSlackApi::DM_ID])]))->toBeFalse()
        ->and(SlackMessage::query()->count())->toBe(0);
});
