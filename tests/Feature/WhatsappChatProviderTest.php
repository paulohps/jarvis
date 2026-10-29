<?php

use App\Actions\Chats\ArchiveChatConversation;
use App\Actions\Chats\MarkChatConversationUnread;
use App\Actions\Chats\MuteChatConversation;
use App\Actions\Chats\PinChatConversation;
use App\Actions\Chats\SetChatConversationNickname;
use App\Actions\Chats\SetChatConversationPriority;
use App\Models\DeliveryStatus;
use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\OutgoingChatFile;
use App\Services\Chats\OutgoingChatMessage;
use App\Services\Whatsapp\WhatsappChatProvider;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Whatsapp\FakeEvolutionApi;

beforeEach(function () {
    $this->seed(LookupSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'UTC'));
    Storage::fake((string) config('filesystems.private'));
    FakeEvolutionApi::install();

    $this->owner = User::factory()->superAdmin()->withModules([Module::WHATSAPP, Module::CHATS])->create(['timezone' => 'UTC']);
    $this->account = WhatsappAccount::factory()->open()->for($this->owner)->create(['instance_name' => 'jarvis', 'phone_number' => '+5511900000000', 'nickname' => 'Pessoal', 'sends_read_receipts' => true]);
    $this->ana = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Ana', 'jid' => '5511911110000@s.whatsapp.net', 'last_message_at' => now()->subMinute(), 'unread_count' => 2]);
    $this->bruno = WhatsappTarget::factory()->contact()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Bruno', 'jid' => '5511922220000@s.whatsapp.net']);
    $this->family = WhatsappTarget::factory()->group()->create(['whatsapp_account_id' => $this->account->id, 'name' => 'Família', 'jid' => '120363000000000001@g.us', 'last_message_at' => now()->subMinute()]);
    $this->entry = $this->ana->conversation;
    $this->driver = app(WhatsappChatProvider::class);

    $this->sent = fn (string $path) => Http::recorded(fn (Request $request): bool => str_contains($request->url(), $path))->map(fn (array $pair) => $pair[0]);
    $this->close = fn () => $this->account->forceFill(['whatsapp_connection_status_id' => WhatsappConnectionStatus::idFor(WhatsappConnectionStatus::CLOSED)])->save();
});

test('the capabilities say what whatsapp supports and go read only when the number is not connected or the contact is blocked', function () {
    $direct = $this->driver->capabilities($this->entry);

    expect($direct->send)->toBeTrue()
        ->and($direct->files)->toBeTrue()
        ->and($direct->threads)->toBeFalse()
        ->and($direct->reactions)->toBeTrue()
        ->and($direct->reactionEmojis)->toBeNull()
        ->and($direct->mentions)->toBeFalse()
        ->and($direct->readReceipts)->toBeTrue()
        ->and($direct->externalLink)->toBeFalse()
        ->and($direct->formatting)->toBe('whatsapp')
        ->and($this->driver->capabilities($this->family->conversation)->mentions)->toBeTrue()
        ->and($this->driver->capabilities()->send)->toBeTrue();

    $this->ana->forceFill(['blocked_at' => now()])->save();
    $blocked = $this->driver->capabilities($this->entry->fresh());
    expect($blocked->send)->toBeFalse()->and($blocked->reactions)->toBeFalse()->and($blocked->history)->toBeTrue();

    $this->ana->forceFill(['blocked_at' => null])->save();
    ($this->close)();
    expect($this->driver->capabilities($this->entry->fresh())->send)->toBeFalse();
});

test('the messages come as the chat screen takes them: mentions by name, files, deleted ones and reactions, with paging', function () {
    $hello = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'M1', 'sender_jid' => $this->ana->jid, 'sender_name' => 'Ana', 'body' => 'Oi @5511922220000, *bora*?', 'mentions' => ['5511922220000@s.whatsapp.net'], 'sent_at' => now()->subMinutes(5)]);
    $photo = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'M2', 'message_type' => 'imageMessage', 'body' => 'Olha', 'media_mime' => 'image/jpeg', 'media_size' => 1234, 'sender_jid' => $this->ana->jid, 'sender_name' => 'Ana', 'sent_at' => now()->subMinutes(4)]);
    $poll = WhatsappChatMessage::factory()->poll()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'M3', 'sender_jid' => $this->ana->jid, 'sender_name' => 'Ana', 'body' => null, 'sent_at' => now()->subMinutes(3)]);
    $gone = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->family->id, 'external_id' => 'M4', 'body' => 'Esquece', 'revoked_at' => now(), 'sent_at' => now()->subMinutes(2)]);
    WhatsappChatReaction::factory()->create(['whatsapp_chat_message_id' => $hello->id, 'reactor' => $this->bruno->jid, 'emoji' => '👍']);
    WhatsappChatReaction::factory()->mine()->create(['whatsapp_chat_message_id' => $hello->id, 'emoji' => '👍']);

    $entry = $this->family->conversation;
    $messages = $this->driver->messages($entry, null, 10);

    expect($messages->pluck('id')->all())->toBe([$hello->friendly_id, $photo->friendly_id, $poll->friendly_id, $gone->friendly_id])
        ->and($messages[0]->conversationId)->toBe($entry->friendly_id)
        ->and($messages[0]->senderName)->toBe('Ana')
        ->and($messages[0]->text)->toBe('Oi @Bruno, *bora*?')
        ->and($messages[0]->html)->toContain('Bruno')->toContain('<strong>bora</strong>')
        ->and($messages[0]->reactions[0]->emoji)->toBe('👍')
        ->and($messages[0]->reactions[0]->count)->toBe(2)
        ->and($messages[0]->reactions[0]->mine)->toBeTrue()
        ->and($messages[0]->reactions[0]->names)->toContain('Bruno', 'You')
        ->and($messages[1]->kind)->toBe('file')
        ->and($messages[1]->text)->toBe('Olha')
        ->and($messages[1]->files[0]->kind)->toBe('image')
        ->and($messages[1]->files[0]->size)->toBe(1234)
        ->and($messages[1]->files[0]->url)->toContain("/api/v1/chats/files/{$entry->friendly_id}/{$photo->friendly_id}/media")->toContain('signature=')
        ->and($messages[2]->text)->toBe('Poll')
        ->and($messages[2]->kind)->toBe('text')
        ->and($messages[3]->isDeleted())->toBeTrue()
        ->and($messages[3]->fromMe)->toBeTrue()
        ->and($messages[3]->canEdit)->toBeFalse();

    expect($this->driver->messages($entry, $poll->friendly_id, 10)->pluck('id')->all())->toBe([$hello->friendly_id, $photo->friendly_id])
        ->and($this->driver->messages($entry, null, 1)->pluck('id')->all())->toBe([$gone->friendly_id])
        ->and($this->driver->hasOlderMessages($entry, $photo->friendly_id))->toBeTrue()
        ->and($this->driver->hasOlderMessages($entry, $hello->friendly_id))->toBeFalse()
        ->and($this->driver->thread($entry, $hello->friendly_id)->pluck('id')->all())->toBe([$hello->friendly_id])
        ->and($this->driver->message($entry, 'wchm_nope'))->toBeNull();
});

test('sending goes through the whatsapp delivery at once and hands the message back as recorded', function () {
    $sent = $this->driver->send($this->entry, new OutgoingChatMessage('Oi Ana'));

    expect($sent->fromMe)->toBeTrue()
        ->and($sent->text)->toBe('Oi Ana')
        ->and($sent->id)->toStartWith('wchm_')
        ->and(($this->sent)('/message/sendText/jarvis'))->toHaveCount(1);

    $delivery = WhatsappDelivery::query()->sole();
    expect($delivery->delivery_status_id)->toBe(DeliveryStatus::idFor(DeliveryStatus::SENT))
        ->and($delivery->external_message_id)->toBe(FakeEvolutionApi::MESSAGE_ID)
        ->and($delivery->triggered_by_id)->toBe($this->owner->id);

    // Answering reads the chat, on the target and on its entry.
    expect($this->ana->refresh()->unread_count)->toBe(0)
        ->and($this->entry->refresh()->unread_count)->toBe(0)
        ->and($this->entry->last_message['text'])->toBe('Oi Ana')
        ->and($this->entry->last_message['from_me'])->toBeTrue()
        ->and($this->entry->last_message_at?->toIso8601String())->toBe(now()->toIso8601String());
});

test('a file goes as a media message and is served back from the private disk', function () {
    $path = tempnam(sys_get_temp_dir(), 'jarvis-chat');
    file_put_contents($path, (string) base64_decode(FakeEvolutionApi::MEDIA_BASE64, true));

    $sent = $this->driver->send($this->entry, new OutgoingChatMessage('Olha', [new OutgoingChatFile($path, 'foto.png', 'image/png')]));

    expect(($this->sent)('/message/sendMedia/jarvis'))->toHaveCount(1)
        ->and($sent->kind)->toBe('file')
        ->and($sent->text)->toBe('Olha')
        ->and($sent->files[0]->kind)->toBe('image')
        ->and($sent->files[0]->url)->not->toBeNull();

    $contents = $this->driver->file($this->entry, $sent->id, 'media');

    expect($contents?->mime)->toBe('image/png')
        ->and($contents?->contents)->toBe((string) base64_decode(FakeEvolutionApi::MEDIA_BASE64, true))
        ->and(($this->sent)('/chat/getBase64FromMediaMessage'))->toHaveCount(0)
        ->and($this->driver->file($this->entry, $sent->id, 'other'))->toBeNull();

    @unlink($path);
});

test('what whatsapp refuses comes back as the channel\'s own error and the delivery is marked failed', function () {
    FakeEvolutionApi::install(['sendText' => Http::response(['message' => 'boom'], 500)]);

    expect(fn () => $this->driver->send($this->entry, new OutgoingChatMessage('Oi')))->toThrow(ChatProviderException::class)
        ->and(WhatsappDelivery::query()->sole()->delivery_status_id)->toBe(DeliveryStatus::idFor(DeliveryStatus::FAILED))
        ->and(fn () => $this->driver->send($this->entry, new OutgoingChatMessage('')))->toThrow(ChatProviderException::class, 'nothing to send');

    ($this->close)();

    expect(fn () => $this->driver->send($this->entry->fresh(), new OutgoingChatMessage('Oi')))->toThrow(ChatProviderException::class, 'not connected')
        ->and(fn () => $this->driver->refresh($this->entry->fresh()))->toThrow(ChatProviderException::class, 'not connected')
        ->and(WhatsappDelivery::query()->count())->toBe(1);
});

test('reacting, editing, deleting and reading go to whatsapp and are kept on the chat', function () {
    $mine = WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'MINE1', 'body' => 'Almoço às 12', 'sent_at' => now()->subMinutes(2)]);
    $hers = WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HERS1', 'body' => 'Bora', 'sent_at' => now()->subMinute()]);

    $this->driver->react($this->entry, $hers->friendly_id, '❤️', true);
    expect($hers->reactions()->where('reactor', WhatsappChatReaction::ME)->value('emoji'))->toBe('❤️');

    // Already on: nothing goes out. Off: the reaction is taken back.
    $this->driver->react($this->entry, $hers->friendly_id, '❤️', true);
    $this->driver->react($this->entry, $hers->friendly_id, '❤️', false);
    expect($hers->reactions()->where('reactor', WhatsappChatReaction::ME)->exists())->toBeFalse()
        ->and(($this->sent)('/message/sendReaction'))->toHaveCount(2);

    $edited = $this->driver->edit($this->entry, $mine->friendly_id, 'Almoço às 13');
    expect($edited->text)->toBe('Almoço às 13')
        ->and($edited->editedAt)->not->toBeNull()
        ->and(fn () => $this->driver->edit($this->entry, $hers->friendly_id, 'Nope'))->toThrow(ChatProviderException::class, 'Only your own texts');

    $this->driver->delete($this->entry, $mine->friendly_id);
    expect($mine->refresh()->isRevoked())->toBeTrue()
        ->and(fn () => $this->driver->delete($this->entry, 'wchm_nope'))->toThrow(ChatProviderException::class, 'no longer here');

    $this->driver->markRead($this->entry);
    expect($this->ana->refresh()->unread_count)->toBe(0)
        ->and($this->ana->read_up_to)->not->toBeNull()
        ->and($this->entry->refresh()->isUnread())->toBeFalse()
        ->and(($this->sent)('/chat/markMessageAsRead'))->toHaveCount(1);
});

test('the participants, the style samples and the accounts come from the whatsapp rows', function () {
    $members = $this->driver->participants($this->family->conversation);

    expect($members->pluck('name')->all())->toBe(['You', 'Ana', 'Bruno'])
        ->and($members->firstWhere('isMe', true)?->handle)->toBe('5511900000000')
        ->and($members->firstWhere('name', 'Ana')?->handle)->toBe('5511911110000')
        ->and($this->driver->participants($this->entry)->pluck('name')->all())->toBe(['Ana', 'You']);

    WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->ana->id, 'body' => 'Valeu!', 'sent_at' => now()->subMinutes(10)]);
    WhatsappChatMessage::factory()->fromMe()->create(['whatsapp_target_id' => $this->family->id, 'body' => 'Chego às 8', 'sent_at' => now()->subMinutes(5)]);
    WhatsappChatMessage::factory()->fromMe()->audio()->create(['whatsapp_target_id' => $this->family->id, 'sent_at' => now()->subMinutes(4)]);
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->family->id, 'body' => 'Beleza', 'sent_at' => now()->subMinutes(3)]);

    expect($this->driver->styleSamples($this->owner, $this->entry, 5))->toBe(['Chego às 8']);

    $accounts = $this->driver->accountsOf($this->owner);
    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]->label)->toBe('Pessoal')
        ->and($accounts[0]->detail)->toBe('+5511900000000')
        ->and($accounts[0]->isUsable)->toBeTrue()
        ->and($accounts[0]->conversationCount)->toBe(2)
        ->and($accounts[0]->url)->toBe(route('whatsapp.accounts.index'));

    ($this->close)();
    expect($this->driver->accountsOf($this->owner)[0]->error)->toContain('not connected');
});

test('what the chat screen sets on a whatsapp conversation is kept on the whatsapp chat and told to the phone', function () {
    WhatsappChatMessage::factory()->create(['whatsapp_target_id' => $this->ana->id, 'external_id' => 'HERS1', 'body' => 'Bora', 'sent_at' => now()->subMinute()]);
    $actor = $this->owner;

    app(PinChatConversation::class)($actor, $this->entry, true);
    app(ArchiveChatConversation::class)($actor, $this->entry, true);
    app(MarkChatConversationUnread::class)($actor, $this->entry);
    app(MuteChatConversation::class)($actor, $this->entry, '8h');
    app(SetChatConversationNickname::class)($actor, $this->entry, 'Aninha');
    app(SetChatConversationPriority::class)($actor, $this->entry, 2);

    expect($this->ana->refresh())
        ->isPinned()->toBeTrue()
        ->isArchived()->toBeTrue()
        ->marked_unread->toBeTrue()
        ->nickname->toBe('Aninha')
        ->priority->toBe(2)
        ->and($this->ana->muted_until?->toIso8601String())->toBe(now()->addHours(8)->toIso8601String())
        ->and($this->entry->refresh())
        ->isPinned()->toBeTrue()
        ->isArchived()->toBeTrue()
        ->marked_unread->toBeTrue()
        ->nickname->toBe('Aninha')
        ->priority->toBe(2)
        ->and(($this->sent)('/chat/archiveChat'))->toHaveCount(1)
        ->and(($this->sent)('/chat/markChatUnread'))->toHaveCount(1);

    // The phone refusing stops the change on both sides.
    FakeEvolutionApi::install(['archiveChat' => Http::response(['message' => 'nope'], 500)]);

    expect(fn () => app(ArchiveChatConversation::class)($actor, $this->entry->refresh(), false))->toThrow(ChatProviderException::class)
        ->and($this->ana->refresh()->isArchived())->toBeTrue()
        ->and($this->entry->refresh()->isArchived())->toBeTrue();
});
