<?php

use App\Actions\Whatsapp\HandleEvolutionWebhook;
use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\ChatContent;
use App\Livewire\Whatsapp\ChatDetails;
use App\Livewire\Whatsapp\NewGroup;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReceipt;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappPollVote;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00', 'UTC'));
    Storage::fake((string) config('filesystems.private'));
    FakeEvolutionApi::install();
    config(['services.evolution.webhook_secret' => 'shared-secret']);

    $this->owner = User::factory()->withModules([Module::WHATSAPP])->create(['timezone' => 'UTC']);
    $this->token = $this->owner->createToken('iPhone')->plainTextToken;
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis', 'phone_number' => '+5511900000000']);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()]);
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net']);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Família', 'jid' => '120363000000000001@g.us', 'last_message_at' => now()]);

    $this->postEvent = fn (string $event, array $data) => $this->postJson(
        route('webhooks.evolution'),
        ['event' => $event, 'instance' => $this->account->instance_name, 'data' => $data],
        [EvolutionApiClient::WEBHOOK_SECRET_HEADER => 'shared-secret'],
    );
    $this->sent = fn (string $path) => Http::recorded(fn (Request $request): bool => str_contains($request->url(), $path))->map(fn (array $pair) => $pair[0]);
});

test('edits, pins, poll votes, timers and replies to texts from whatsapp are kept', function () {
    $mine = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'MINE1', 'body' => 'Almoço às 12']);
    $poll = WhatsappChatMessage::factory()->poll()->fromMe()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'POLL1']);

    // An edit comes as the bare protocolMessage; any other protocolMessage is left out.
    ($this->postEvent)('messages.edited', ['key' => ['id' => 'MINE1', 'remoteJid' => $this->family->jid, 'fromMe' => true], 'type' => 14, 'editedMessage' => ['conversation' => 'Almoço às 13'], 'timestampMs' => now()->getTimestampMs()])->assertOk();
    ($this->postEvent)('messages.edited', ['key' => ['id' => 'MINE1', 'remoteJid' => $this->family->jid, 'fromMe' => true], 'type' => 0])->assertOk();

    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => 'PIN1', 'participant' => $this->bruno->jid],
        'messageType' => 'pinInChatMessage',
        'message' => ['pinInChatMessage' => ['key' => ['id' => 'MINE1'], 'type' => 1, 'senderTimestampMs' => now()->getTimestampMs()], 'messageContextInfo' => ['messageAddOnDurationInSecs' => 86400]],
        'messageTimestamp' => now()->getTimestamp(),
    ])->assertOk();

    $vote = fn (string $id, array $options, int $at) => ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => $id, 'participant' => $this->bruno->jid],
        'messageType' => 'pollUpdateMessage',
        'message' => ['pollUpdateMessage' => ['pollCreationMessageKey' => ['id' => 'POLL1'], 'vote' => ['selectedOptions' => $options], 'senderTimestampMs' => $at]],
        'messageTimestamp' => now()->getTimestamp(),
    ]);
    $vote('V1', ['Sim', 'hash-not-an-option'], now()->getTimestampMs())->assertOk();

    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => 'TIMER1', 'participant' => $this->bruno->jid],
        'messageType' => 'protocolMessage',
        'message' => ['protocolMessage' => ['type' => 'EPHEMERAL_SETTING', 'ephemeralExpiration' => 604800]],
        'messageTimestamp' => now()->getTimestamp(),
    ])->assertOk();

    // The Evolution API turns a text reply into a `conversation`: its quote comes beside the message.
    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => 'IN1', 'participant' => $this->bruno->jid],
        'pushName' => 'Bruno',
        'messageType' => 'conversation',
        'message' => ['conversation' => 'Fechado'],
        'contextInfo' => ['stanzaId' => 'MINE1', 'participant' => '5511900000000@s.whatsapp.net', 'quotedMessage' => ['conversation' => 'Almoço às 13']],
        'messageTimestamp' => now()->getTimestamp(),
    ])->assertOk();

    expect($mine->refresh())
        ->body->toBe('Almoço às 13')
        ->edited_at->not->toBeNull()
        ->isPinned()->toBeTrue()
        ->pinned_until->toEqual(now()->addDay())
        ->and(WhatsappPollVote::query()->sole())->options->toBe(['Sim'])->voter->toBe($this->bruno->jid)
        ->and($this->family->refresh()->ephemeral_seconds)->toBe(604800)
        ->and(WhatsappChatMessage::query()->where('external_id', 'IN1')->sole()->quoted_external_id)->toBe('MINE1');

    // Taking the vote back leaves no option; an older one arriving late changes nothing.
    $vote('V2', [], now()->addMinute()->getTimestampMs())->assertOk();
    $vote('V0', ['Não'], now()->subMinute()->getTimestampMs())->assertOk();
    expect(WhatsappPollVote::query()->sole()->options)->toBe([])
        ->and($poll->refresh()->load('pollVotes')->pollTally())->each->toMatchArray(['votes' => 0]);

    ($this->postEvent)('messages.upsert', [
        'key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => 'UNPIN1', 'participant' => $this->bruno->jid],
        'messageType' => 'pinInChatMessage',
        'message' => ['pinInChatMessage' => ['key' => ['id' => 'MINE1'], 'type' => 2]],
        'messageTimestamp' => now()->getTimestamp(),
    ])->assertOk();
    expect($mine->refresh()->isPinned())->toBeFalse();

    $listed = collect($this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->family))->assertOk()->json('data'))->keyBy('friendly_id');

    expect($listed[$mine->friendly_id])->toMatchArray(['body' => 'Almoço às 13', 'edited' => true, 'pinned' => false])
        ->and($listed->firstWhere('body', 'Fechado')['quoted']['friendly_id'])->toBe($mine->friendly_id);
});

test('group events and the receipts of a one-to-one chat are kept', function () {
    Cache::put($this->family->participantsCacheKey(), [], 600);
    $sent = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'OUT1', 'receipt' => WhatsappChatMessage::RECEIPT_SENT]);

    ($this->postEvent)('GROUP_PARTICIPANTS_UPDATE', ['id' => $this->family->jid, 'action' => 'add', 'participants' => [['id' => '5511933330000@s.whatsapp.net']]])->assertOk();
    ($this->postEvent)('groups.upsert', [['id' => $this->family->jid, 'subject' => 'Família Silva', 'desc' => 'Domingo tem almoço', 'ephemeralDuration' => 86400]])->assertOk();
    ($this->postEvent)('messages.update', ['keyId' => 'OUT1', 'remoteJid' => $this->ana->jid, 'fromMe' => true, 'status' => 'DELIVERY_ACK'])->assertOk();
    $this->travel(5)->minutes();
    ($this->postEvent)('messages.update', ['keyId' => 'OUT1', 'remoteJid' => $this->ana->jid, 'fromMe' => true, 'status' => 'READ'])->assertOk();

    // What the number sent through the API comes only as `send.message`.
    ($this->postEvent)('send.message', ['key' => ['remoteJid' => $this->ana->jid, 'fromMe' => true, 'id' => 'API1'], 'messageType' => 'conversation', 'message' => ['conversation' => 'Pela API'], 'messageTimestamp' => now()->getTimestamp(), 'status' => 'PENDING'])->assertOk();

    $receipt = WhatsappChatReceipt::query()->sole();

    expect(Cache::has($this->family->participantsCacheKey()))->toBeFalse()
        ->and($this->family->refresh())->name->toBe('Família Silva')->description->toBe('Domingo tem almoço')->ephemeral_seconds->toBe(86400)
        ->and($sent->refresh()->receipt)->toBe(WhatsappChatMessage::RECEIPT_READ)
        ->and($receipt->delivered_at)->toEqual(now()->subMinutes(5))
        ->and($receipt->read_at)->toEqual(now())
        ->and(WhatsappChatMessage::query()->where('external_id', 'API1')->exists())->toBeTrue();

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.info', [$this->ana, $sent->friendly_id]))
        ->assertOk()
        ->assertJsonPath('data.receipt', 'read')
        ->assertJsonPath('data.per_member', true)
        ->assertJsonPath('data.read_at', now()->toIso8601String());

    expect(app(HandleEvolutionWebhook::class)(['event' => 'group-participants.update', 'instance' => 'jarvis', 'data' => ['id' => 'unknown@g.us']]))->toBeFalse();
});

test('a reply quotes the message, mentions reach a group, and both show once sent', function () {
    $question = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'Q1', 'sender_jid' => $this->bruno->jid, 'body' => 'Quem traz o bolo?']);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->family->friendly_id])
        ->call('reply', $question->friendly_id)
        ->assertSeeHtml('data-chat-reply-bar="'.$question->friendly_id.'"')
        ->set('text', 'Eu levo, @5511911110000 traz o café')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('replyToId', null);

    $request = ($this->sent)('/message/sendText/')->sole();

    expect($request['quoted'])->toBe(['key' => ['remoteJid' => $this->family->jid, 'fromMe' => false, 'id' => 'Q1', 'participant' => $this->bruno->jid], 'message' => ['conversation' => 'Quem traz o bolo?']])
        ->and($request['mentioned'])->toBe(['5511911110000@s.whatsapp.net']);

    $reply = WhatsappChatMessage::query()->where('external_id', FakeEvolutionApi::MESSAGE_ID)->sole();

    expect($reply)->quoted_external_id->toBe('Q1')->mentions->toBe(['5511911110000@s.whatsapp.net'])
        ->and($reply->quoted['body'])->toBe('Quem traz o bolo?');

    // No mention in a one-to-one chat; a reply to a message of another chat is refused.
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.send', $this->ana), ['text' => '@5511922220000 oi', 'reply_to' => $question->friendly_id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reply_to');
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.send', $this->ana), ['text' => '@5511922220000 oi'])->assertAccepted();

    expect(($this->sent)('/message/sendText/')->last()->data())->toBe(['number' => $this->ana->jid, 'text' => '@5511922220000 oi']);
});

test('polls, locations, contacts and stickers go out and show in the chat', function () {
    $sentAs = fn (string $id) => Http::response(['key' => ['remoteJid' => $this->family->jid, 'fromMe' => true, 'id' => $id], 'status' => 'PENDING']);
    FakeEvolutionApi::install(['sendPoll' => $sentAs('POLL1'), 'sendLocation' => $sentAs('LOC1'), 'sendContact' => $sentAs('VC1'), 'sendSticker' => $sentAs('STK1')]);
    $component = Livewire::actingAs($this->owner)->test(ChatContent::class, ['chatId' => $this->family->friendly_id]);

    $component->call('compose', 'poll')
        ->set('question', 'Pizza ou sushi?')
        ->set('options', ['Pizza', 'pizza'])
        ->call('send')
        ->assertHasErrors('options.1');
    $component->set('options', ['Pizza', 'Sushi'])->call('addOption')->set('options.2', 'Tanto faz')->set('multiple', true)
        ->call('send')
        ->assertHasNoErrors()
        ->assertDispatched('whatsapp-content-sent')
        ->assertSet('show', false);

    expect(($this->sent)('/message/sendPoll/')->sole()->data())->toBe(['number' => $this->family->jid, 'name' => 'Pizza ou sushi?', 'selectableCount' => 0, 'values' => ['Pizza', 'Sushi', 'Tanto faz']])
        ->and(WhatsappChatMessage::query()->where('message_type', 'pollCreationMessage')->sole()->payload['poll']['options'])->toBe(['Pizza', 'Sushi', 'Tanto faz']);

    $component->call('compose', 'location')->set('latitude', '-23,5613')->set('longitude', '-46.6565')->set('placeName', 'MASP')->call('send')->assertHasNoErrors();
    $component->call('compose', 'contacts')->set('pickedContacts', [$this->bruno->friendly_id])->call('send')->assertHasNoErrors();
    $component->call('compose', 'sticker')->set('sticker', UploadedFile::fake()->image('sticker.png', 512, 512))->call('send')->assertHasNoErrors();
    $component->call('compose', 'location')->set('latitude', 'north')->set('longitude', '0')->call('send')->assertHasErrors('latitude');

    expect(($this->sent)('/message/sendLocation/')->sole()->data())->toMatchArray(['latitude' => -23.5613, 'longitude' => -46.6565, 'name' => 'MASP', 'address' => ''])
        ->and(($this->sent)('/message/sendContact/')->sole()['contact'])->toBe([['fullName' => 'Bruno', 'phoneNumber' => '+5511922220000', 'wuid' => '5511922220000']])
        ->and(($this->sent)('/message/sendSticker/')->sole()['notConvertSticker'])->toBeFalse()
        ->and(WhatsappDelivery::query()->count())->toBe(4);

    $listed = collect($this->withToken($this->token)->getJson(route('api.whatsapp.conversations.messages', $this->family))->assertOk()->json('data'));

    expect($listed->pluck('type')->sort()->values()->all())->toBe(['contactMessage', 'locationMessage', 'pollCreationMessage', 'stickerMessage'])
        ->and($listed->firstWhere('type', 'contactMessage')['contacts'])->toBe([['name' => 'Bruno', 'numbers' => ['+5511922220000']]])
        ->and($listed->firstWhere('type', 'locationMessage')['location'])->toBe(['latitude' => -23.5613, 'longitude' => -46.6565, 'name' => 'MASP'])
        ->and($listed->firstWhere('type', 'stickerMessage')['sticker'])->toBeTrue();

    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.poll', $this->ana), ['question' => 'Vamos?', 'options' => ['Sim', 'Não']])->assertAccepted();
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.contacts', $this->ana), ['contacts' => [$this->family->friendly_id]])->assertUnprocessable()->assertJsonValidationErrors('contacts');
});

test('the owner edits and deletes for everyone what the number sent, within whatsapp limits', function () {
    $recent = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'R1', 'body' => 'Chego às 8', 'sent_at' => now()->subMinutes(5)]);
    $old = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'O1', 'body' => 'Velha', 'sent_at' => now()->subDays(3)]);
    $theirs = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'T1', 'body' => 'Dela', 'sent_at' => now()->subMinute()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class, ['chatId' => $this->ana->friendly_id])
        ->assertSeeHtml('data-chat-edit="'.$recent->friendly_id.'"')
        ->assertDontSeeHtml('data-chat-edit="'.$theirs->friendly_id.'"')
        ->assertDontSeeHtml('data-chat-delete-everyone="'.$old->friendly_id.'"')
        ->call('startEdit', $recent->friendly_id)
        ->assertSet('editText', 'Chego às 8')
        ->set('editText', 'Chego às 9')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSet('showEdit', false)
        ->call('confirmDeleteForEveryone', $recent->friendly_id)
        ->call('deleteForEveryone')
        ->call('toggleStar', $theirs->friendly_id)
        ->call('openMessageInfo', $recent->friendly_id)
        ->assertSeeHtml('data-chat-message-info-modal="'.$recent->friendly_id.'"');

    expect(($this->sent)('/chat/updateMessage/')->sole()->data())->toBe(['number' => $this->ana->jid, 'key' => ['id' => 'R1', 'remoteJid' => $this->ana->jid, 'fromMe' => true], 'text' => 'Chego às 9'])
        ->and(($this->sent)('/chat/deleteMessageForEveryone/')->sole()->data())->toBe(['remoteJid' => $this->ana->jid, 'fromMe' => true, 'id' => 'R1'])
        ->and($recent->refresh())->body->toBe('Chego às 9')->edited_at->not->toBeNull()->revoked_at->not->toBeNull()
        ->and($theirs->refresh()->starred_at)->not->toBeNull();

    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.edit', [$this->ana, $old->friendly_id]), ['text' => 'Nova'])->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->withToken($this->token)->deleteJson(route('api.whatsapp.conversations.delete', [$this->ana, $theirs->friendly_id]))->assertUnprocessable();

    FakeEvolutionApi::install(['editMessage' => Http::response(['message' => 'Message not compatible'], 400)]);
    $fresh = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'F1', 'body' => 'Oi', 'sent_at' => now()]);
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.edit', [$this->ana, $fresh->friendly_id]), ['text' => 'Olá'])->assertStatus(502);
    expect($fresh->refresh()->body)->toBe('Oi');
});

test('chats are archived, marked unread, muted and blocked, from the web and the apps', function () {
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'LAST1', 'sent_at' => now()]);

    Livewire::actingAs($this->owner)
        ->test(Chat::class)
        ->call('archiveChat', $this->ana->friendly_id, true)
        ->assertDontSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"')
        ->set('showArchived', true)
        ->assertSeeInIsland('data-chat-item="'.$this->ana->friendly_id.'"')
        ->call('markChatUnread', $this->ana->friendly_id, true);

    expect(($this->sent)('/chat/archiveChat/')->sole()->data())->toBe(['archive' => true, 'lastMessage' => ['key' => ['remoteJid' => $this->ana->jid, 'fromMe' => false, 'id' => 'LAST1'], 'messageTimestamp' => now()->getTimestamp()], 'chat' => $this->ana->jid])
        ->and(($this->sent)('/chat/markChatUnread/')->count())->toBe(1)
        ->and($this->ana->refresh())->isArchived()->toBeTrue()->marked_unread->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.update', $this->ana), ['archived' => false, 'muted' => '8h', 'blocked' => true])
        ->assertOk()
        ->assertJsonPath('data.archived', false)
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.muted_until', now()->addHours(8)->toIso8601String());

    expect(($this->sent)('/chat/updateBlockStatus/')->sole()->data())->toBe(['number' => $this->ana->jid, 'status' => 'block']);

    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index'))->assertJsonFragment(['friendly_id' => $this->ana->friendly_id]);
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.update', $this->family), ['blocked' => true])->assertUnprocessable();
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.update', $this->family), ['archived' => true])->assertOk();
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index'))->assertJsonMissing(['friendly_id' => $this->family->friendly_id]);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.index', ['archived' => 1]))->assertJsonPath('data.0.friendly_id', $this->family->friendly_id);
});

test('groups are created and managed by their admin, on the web and the apps', function () {
    Livewire::actingAs($this->owner)
        ->test(NewGroup::class, ['accountId' => $this->account->friendly_id])
        ->call('open')
        ->set('subject', 'Viagem')
        ->set('members', [$this->ana->friendly_id, $this->bruno->friendly_id])
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('whatsapp-open-chat');

    $trip = WhatsappTarget::query()->where('jid', FakeEvolutionApi::CREATED_GROUP_JID)->sole();

    expect(($this->sent)('/group/create/')->sole()->data())->toBe(['subject' => 'Viagem', 'participants' => ['5511911110000', '5511922220000']])
        ->and($trip)->name->toBe('Viagem')->isGroup()->toBeTrue();

    $details = Livewire::actingAs($this->owner)
        ->test(ChatDetails::class, ['chatId' => $this->family->friendly_id])
        ->call('load')
        ->assertSet('infoAdminsOnly', true)
        ->assertSeeHtml('data-chat-group-member="5511922220000@s.whatsapp.net"')
        ->assertSeeHtml('data-chat-group-permissions')
        ->set('ephemeral', '604800')
        ->call('updateMember', '5511922220000@s.whatsapp.net', 'demote')
        ->call('showInviteLink')
        ->assertSee('https://chat.whatsapp.com/'.FakeEvolutionApi::INVITE_CODE)
        ->call('leave');

    expect($this->family->refresh())->description->toBe('Sunday lunch')->ephemeral_seconds->toBe(604800)->isArchived()->toBeTrue()
        ->and(($this->sent)('/group/updateParticipant/')->sole()->data())->toBe(['groupJid' => $this->family->jid, 'action' => 'demote', 'participants' => ['5511922220000@s.whatsapp.net']])
        ->and(($this->sent)('/group/toggleEphemeral/')->sole()['expiration'])->toBe(604800)
        ->and(($this->sent)('/group/leaveGroup/')->sole()->url())->toContain('groupJid=120363000000000001%40g.us');

    $details->assertDispatched('whatsapp-chat-changed');

    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.group', $this->family))
        ->assertOk()
        ->assertJsonPath('data.is_admin', true)
        ->assertJsonPath('data.members.0.me', true)
        ->assertJsonPath('data.members.1.name', 'Bruno')
        ->assertJsonPath('data.members.2.name', 'Ana');
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.group.members', $this->family), ['action' => 'add', 'participants' => ['+55 11 93333-0000']])
        ->assertJsonValidationErrors('participants.0');
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.group.members', $this->family), ['action' => 'add', 'participants' => ['+5511933330000']])
        ->assertOk()
        ->assertJsonPath('results', ['5511933330000@s.whatsapp.net' => true]);
    $this->withToken($this->token)->patchJson(route('api.whatsapp.conversations.group.update', $this->family), ['subject' => 'Família Silva'])->assertOk()->assertJsonPath('data.admins_only', false);
    expect(($this->sent)('/group/updateGroupSubject/')->sole()['subject'])->toBe('Família Silva');
    $this->withToken($this->token)->postJson(route('api.whatsapp.conversations.group.invite', $this->family), ['revoke' => true])->assertOk()->assertJsonPath('url', 'https://chat.whatsapp.com/'.FakeEvolutionApi::INVITE_CODE);
    $this->withToken($this->token)->getJson(route('api.whatsapp.conversations.group', $this->ana))->assertUnprocessable();
    $this->withToken($this->token)->postJson(route('api.whatsapp.groups.store'), ['account' => $this->account->friendly_id, 'subject' => 'X', 'members' => [$this->family->friendly_id]])->assertUnprocessable();
});

test('nobody manages the chats of somebody else\'s number', function () {
    $stranger = User::factory()->withModules([Module::WHATSAPP])->create();
    $message = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'sent_at' => now()]);

    $this->withToken($stranger->createToken('x')->plainTextToken)->deleteJson(route('api.whatsapp.conversations.delete', [$this->ana, $message->friendly_id]))->assertForbidden();
    $this->withToken($stranger->createToken('y')->plainTextToken)->getJson(route('api.whatsapp.conversations.group', $this->family))->assertForbidden();
    $this->withToken($stranger->createToken('z')->plainTextToken)->patchJson(route('api.whatsapp.conversations.update', $this->ana), ['blocked' => true])->assertForbidden();

    expect(Http::recorded())->toBeEmpty();
});
