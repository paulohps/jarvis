<?php

use App\Events\ChatConversationUpdated;
use App\Models\ChatConversation;
use App\Models\Module;
use App\Models\SlackAccount;
use App\Models\SlackConversation;
use App\Models\SlackMessage;
use App\Models\SlackUser;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\OutgoingChatFile;
use App\Services\Chats\OutgoingChatMessage;
use App\Services\Slack\SlackChatProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Support\Facades\Event;
use Tests\Fixtures\Slack\FakeSlackApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Event::fake([ChatConversationUpdated::class]);
    FakeSlackApi::install();

    $this->owner = User::factory()->withModules([Module::CHATS])->create(['timezone' => 'UTC']);
    $this->account = SlackAccount::factory()->for($this->owner)->create(['team_id' => FakeSlackApi::TEAM_ID, 'slack_user_id' => FakeSlackApi::USER_ID, 'team_domain' => 'acme']);
    SlackUser::factory()->for($this->account, 'account')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'name' => 'ana', 'real_name' => 'Ana Lima', 'display_name' => 'Ana']);
    SlackUser::factory()->for($this->account, 'account')->create(['slack_user_id' => FakeSlackApi::USER_ID, 'name' => 'paulo', 'real_name' => 'Paulo', 'display_name' => 'Paulo']);
    $this->dm = SlackConversation::factory()->for($this->account, 'account')->directWith(FakeSlackApi::ANA_ID)->create(['channel_id' => FakeSlackApi::DM_ID, 'history_exhausted_at' => now()]);
    $this->entry = ChatConversation::factory()->for($this->owner)->forSlack($this->dm)->direct()->create(['name' => 'Ana Lima']);

    $this->slackMessage = fn (string $ts, string $user, string $text, array $extra = []): SlackMessage => SlackMessage::factory()->for($this->dm, 'conversation')->create([
        'ts' => $ts, 'slack_user_id' => $user, 'text' => $text, 'sent_at' => SlackMessage::instantOf($ts), ...$extra,
    ]);
    $this->driver = app(SlackChatProvider::class);
});

test('the capabilities say what slack supports and go read only when the conversation cannot be written to', function () {
    $capabilities = $this->driver->capabilities($this->entry);
    expect($capabilities->send)->toBeTrue()
        ->and($capabilities->threads)->toBeTrue()
        ->and($capabilities->reactions)->toBeTrue()
        ->and($capabilities->reactionEmojis)->toContain('👍', '❤️')
        ->and($capabilities->formatting)->toBe('mrkdwn')
        ->and($capabilities->typing)->toBeFalse();

    $this->dm->forceFill(['is_archived' => true])->save();
    expect($this->driver->capabilities($this->entry->fresh())->send)->toBeFalse();

    $this->dm->forceFill(['is_archived' => false])->save();
    $this->account->forceFill(['failed_at' => now(), 'last_error' => 'token_revoked'])->save();
    expect($this->driver->capabilities($this->entry->fresh())->send)->toBeFalse()
        ->and(fn () => $this->driver->send($this->entry->fresh(), new OutgoingChatMessage('x')))->toThrow(ChatProviderException::class);
});

test('the messages come out as the chat screen takes them, with names, html, reactions and threads', function () {
    ($this->slackMessage)('1700000100.000100', FakeSlackApi::ANA_ID, 'Morning *team* :wave: <https://example.com|site>', ['reactions' => [['name' => 'thumbsup', 'users' => [FakeSlackApi::USER_ID, FakeSlackApi::ANA_ID]]]]);
    $parent = ($this->slackMessage)('1700000200.000100', FakeSlackApi::USER_ID, 'Sure <@'.FakeSlackApi::ANA_ID.'>', ['thread_ts' => '1700000200.000100', 'reply_count' => 1]);
    ($this->slackMessage)('1700000250.000100', FakeSlackApi::ANA_ID, 'thanks', ['thread_ts' => '1700000200.000100']);
    ($this->slackMessage)('1700000300.000100', FakeSlackApi::ANA_ID, 'joined', ['subtype' => 'channel_join']);

    $messages = $this->driver->messages($this->entry, null, 50);

    expect($messages->pluck('text')->all())->toBe(['Morning *team* 👋 site', 'Sure @Ana', 'joined'])
        ->and($messages[0]->senderName)->toBe('Ana')
        ->and($messages[0]->fromMe)->toBeFalse()
        ->and($messages[0]->html)->toContain('<strong>team</strong>', 'chat-link')
        ->and($messages[0]->reactions[0]->emoji)->toBe('👍')
        ->and($messages[0]->reactions[0]->count)->toBe(2)
        ->and($messages[0]->reactions[0]->mine)->toBeTrue()
        ->and($messages[0]->reactions[0]->names)->toBe(['Paulo', 'Ana'])
        ->and($messages[1]->fromMe)->toBeTrue()
        ->and($messages[1]->canEdit)->toBeTrue()
        ->and($messages[1]->replyCount)->toBe(1)
        ->and($messages[1]->threadId)->toBe($parent->friendly_id)
        ->and($messages[1]->externalUrl)->toBe('https://acme.slack.com/archives/'.FakeSlackApi::DM_ID.'/p1700000200000100')
        ->and($messages[2]->kind)->toBe('system')
        ->and($messages[2]->canDelete)->toBeFalse();

    expect($this->driver->messages($this->entry, $messages[1]->id, 50)->pluck('text')->all())->toBe(['Morning *team* 👋 site'])
        ->and($this->driver->hasOlderMessages($this->entry, $messages[1]->id))->toBeTrue()
        ->and($this->driver->hasOlderMessages($this->entry, $messages[0]->id))->toBeFalse();

    $thread = $this->driver->thread($this->entry, $parent->friendly_id);
    expect($thread->pluck('text')->all())->toBe(['Sure @Ana', 'thanks'])
        ->and($thread[1]->isReply())->toBeTrue();
});

test('sending posts the text as slack mrkdwn with mentions and records what came back', function () {
    $sent = $this->driver->send($this->entry, new OutgoingChatMessage('Hi @ana, <3 & bye'));

    expect(FakeSlackApi::called('chat.postMessage', ['channel' => FakeSlackApi::DM_ID, 'text' => 'Hi <@'.FakeSlackApi::ANA_ID.'>, &lt;3 &amp; bye']))->toBeTrue()
        ->and($sent->fromMe)->toBeTrue()
        ->and($sent->text)->toBe('posted text')
        ->and(SlackMessage::query()->where('ts', '1700000500.000100')->exists())->toBeTrue()
        ->and($this->entry->fresh()->last_message['text'])->toBe('posted text');

    $parent = ($this->slackMessage)('1700000200.000100', FakeSlackApi::ANA_ID, 'question');
    $this->driver->send($this->entry, new OutgoingChatMessage('answer', [], $parent->friendly_id));
    expect(FakeSlackApi::called('chat.postMessage', ['thread_ts' => '1700000200.000100']))->toBeTrue();
});

test('a file goes through the external upload and is found among the latest messages', function () {
    $path = tempnam(sys_get_temp_dir(), 'slack');
    file_put_contents($path, '%PDF-1.4 fake');
    FakeSlackApi::install(['conversations.history' => ['ok' => true, 'messages' => [
        FakeSlackApi::message('1700000600.000100', FakeSlackApi::USER_ID, 'see attached', ['subtype' => 'file_share', 'files' => [['id' => 'F0000000001', 'name' => 'report.pdf', 'title' => 'report.pdf', 'mimetype' => 'application/pdf', 'size' => 13, 'url_private' => FakeSlackApi::FILE_URL]]]),
    ], 'has_more' => false]]);

    $sent = $this->driver->send($this->entry, new OutgoingChatMessage('see attached', [new OutgoingChatFile($path, 'report.pdf', 'application/pdf')]));

    expect(FakeSlackApi::called('files.getUploadURLExternal', ['filename' => 'report.pdf', 'length' => '13']))->toBeTrue()
        ->and(FakeSlackApi::called('files.completeUploadExternal', ['channel_id' => FakeSlackApi::DM_ID, 'initial_comment' => 'see attached']))->toBeTrue()
        ->and($sent->kind)->toBe('file')
        ->and($sent->files[0]->name)->toBe('report.pdf')
        ->and($sent->files[0]->url)->toContain('/api/v1/chats/files/'.$this->entry->friendly_id.'/'.$sent->id.'/F0000000001');

    $contents = $this->driver->file($this->entry, $sent->id, 'F0000000001');
    expect($contents?->contents)->toBe('%PDF-1.4 fake')
        ->and($contents?->mime)->toBe('application/pdf')
        ->and($this->driver->file($this->entry, $sent->id, 'FNOPE'))->toBeNull();

    $this->actingAs($this->owner)->get($sent->files[0]->url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get($sent->files[0]->url)->assertOk();
    $this->get(str_replace('signature=', 'signature=x', $sent->files[0]->url))->assertForbidden();
});

test('reactions, edits and deletions go to slack by name and update the record', function () {
    $mine = ($this->slackMessage)('1700000200.000100', FakeSlackApi::USER_ID, 'Sure');
    $theirs = ($this->slackMessage)('1700000100.000100', FakeSlackApi::ANA_ID, 'Morning');

    $this->driver->react($this->entry, $theirs->friendly_id, '👍', true);
    expect(FakeSlackApi::called('reactions.add', ['name' => '+1', 'timestamp' => '1700000100.000100']))->toBeTrue()
        ->and($theirs->fresh()->reactions)->toBe([['name' => '+1', 'users' => [FakeSlackApi::USER_ID]]]);

    $this->driver->react($this->entry, $theirs->friendly_id, '👍', false);
    expect(FakeSlackApi::called('reactions.remove', ['name' => '+1']))->toBeTrue()
        ->and($theirs->fresh()->reactions)->toBe([]);

    expect(fn () => $this->driver->react($this->entry, $theirs->friendly_id, '🫥', true))->toThrow(ChatProviderException::class);

    $edited = $this->driver->edit($this->entry, $mine->friendly_id, 'edited text');
    expect(FakeSlackApi::called('chat.update', ['ts' => '1700000200.000100', 'text' => 'edited text']))->toBeTrue()
        ->and($edited->text)->toBe('edited text')
        ->and($edited->editedAt)->not->toBeNull();

    expect(fn () => $this->driver->edit($this->entry, $theirs->friendly_id, 'nope'))->toThrow(ChatProviderException::class, __('Only your own messages can be edited.'));

    $this->driver->delete($this->entry, $mine->friendly_id);
    expect(FakeSlackApi::called('chat.delete', ['ts' => '1700000200.000100']))->toBeTrue()
        ->and($mine->fresh()->isDeleted())->toBeTrue();
});

test('a token slack no longer accepts fails the account from any call', function () {
    FakeSlackApi::install(['chat.postMessage' => ['ok' => false, 'error' => 'account_inactive']]);

    expect(fn () => $this->driver->send($this->entry, new OutgoingChatMessage('x')))->toThrow(ChatProviderException::class)
        ->and($this->account->fresh()->hasFailed())->toBeTrue()
        ->and($this->driver->accountsOf($this->owner)->first()->isUsable)->toBeFalse();
});

test('refreshing brings the conversation and its history from slack, older ones on demand', function () {
    $this->dm->forceFill(['history_exhausted_at' => null])->save();

    $this->driver->refresh($this->entry);
    expect(SlackMessage::query()->where('slack_conversation_id', $this->dm->id)->count())->toBe(3)
        ->and($this->dm->fresh()->history_exhausted_at)->not->toBeNull();

    $oldest = $this->driver->messages($this->entry, null, 1)->first();
    FakeSlackApi::install(['conversations.history' => ['ok' => true, 'messages' => [FakeSlackApi::message('1690000000.000100', FakeSlackApi::ANA_ID, 'way back')], 'has_more' => false]]);
    $this->driver->refresh($this->entry, $oldest->id);

    expect(FakeSlackApi::called('conversations.history', ['latest' => $oldest->id === null ? '' : SlackMessage::query()->where('friendly_id', $oldest->id)->sole()->ts]))->toBeTrue()
        ->and(SlackMessage::query()->where('text', 'way back')->exists())->toBeTrue();
});

test('the style samples are the owner messages of their other conversations', function () {
    $other = SlackConversation::factory()->for($this->account, 'account')->create();
    ChatConversation::factory()->for($this->owner)->forSlack($other)->create();
    SlackMessage::factory()->for($other, 'conversation')->create(['slack_user_id' => FakeSlackApi::USER_ID, 'text' => 'from me elsewhere <@'.FakeSlackApi::ANA_ID.'>']);
    SlackMessage::factory()->for($other, 'conversation')->create(['slack_user_id' => FakeSlackApi::ANA_ID, 'text' => 'from ana']);
    ($this->slackMessage)('1700000200.000100', FakeSlackApi::USER_ID, 'from me here');

    expect($this->driver->styleSamples($this->owner, $this->entry, 10))->toBe(['from me elsewhere @'.FakeSlackApi::ANA_ID]);
});
