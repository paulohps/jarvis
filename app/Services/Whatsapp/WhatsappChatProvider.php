<?php

namespace App\Services\Whatsapp;

use App\Actions\Chats\ApplyChatConversationsBulkAction;
use App\Actions\Whatsapp\ArchiveWhatsappChat;
use App\Actions\Whatsapp\DeleteWhatsappChatMessageForEveryone;
use App\Actions\Whatsapp\EditWhatsappChatMessage;
use App\Actions\Whatsapp\ListWhatsappGroupParticipants;
use App\Actions\Whatsapp\LoadWhatsappChatMedia;
use App\Actions\Whatsapp\LoadWhatsappSenderPictures;
use App\Actions\Whatsapp\MarkWhatsappChatUnread;
use App\Actions\Whatsapp\PinWhatsappChat;
use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Actions\Whatsapp\SendWhatsappChatMessage;
use App\Actions\Whatsapp\SendWhatsappReaction;
use App\Actions\Whatsapp\SendWhatsappReadReceipts;
use App\Actions\Whatsapp\SetWhatsappChatNickname;
use App\Actions\Whatsapp\SetWhatsappChatPriority;
use App\Actions\Whatsapp\SyncWhatsappChat;
use App\Exceptions\EvolutionApiException;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use App\Models\WhatsappTarget;
use App\Services\Chats\ChatAccountData;
use App\Services\Chats\ChatCapabilities;
use App\Services\Chats\ChatFileContents;
use App\Services\Chats\ChatFileData;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatParticipantData;
use App\Services\Chats\ChatProviderDriver;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatReactionData;
use App\Services\Chats\OutgoingChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * WhatsApp on the chat layer (Key Concept "Chat channel"): the chats of
 * the numbers the user connected (WhatsappAccount), read from
 * `whatsapp_chat_messages` — filled by the Evolution API webhooks and the
 * history syncs — and written through the WhatsApp Actions, so the chat
 * screen sends, reacts, edits and deletes exactly as the WhatsApp screen
 * does (deliveries logged, the phone told). A chat of a number that is not
 * connected, or with a contact the owner blocked, is read only.
 *
 * Message ids are the friendly ids of the WhatsappChatMessages (`wchm_…`);
 * the one file a message may carry has the id `media`. Any emoji reacts.
 * The texts keep WhatsApp's markup (`*bold*`, `_italic_`…); mentions are
 * `@` and the number, so the participants' handles are their numbers.
 *
 * The entry's own state (pin, archive, mute, unread mark, nickname,
 * priority) lives on the WhatsappTarget: entryChanged() writes it there
 * through the WhatsApp Actions, which also tell the phone.
 */
final class WhatsappChatProvider implements ChatProviderDriver
{
    /**
     * The message types that are a plain text.
     *
     * @var list<string>
     */
    private const array TEXT_TYPES = ['conversation', 'extendedTextMessage'];

    private const string FILE_ID = 'media';

    /**
     * How long a signed file URL handed to the screens works.
     */
    private const int FILE_URL_MINUTES = 60 * 24;

    public function __construct(
        private readonly SendWhatsappChatMessage $sendMessage,
        private readonly SendWhatsappChatContent $sendContent,
        private readonly SendWhatsappReaction $sendReaction,
        private readonly EditWhatsappChatMessage $editMessage,
        private readonly DeleteWhatsappChatMessageForEveryone $deleteMessage,
        private readonly SendWhatsappReadReceipts $sendReadReceipts,
        private readonly SyncWhatsappChat $syncChat,
        private readonly LoadWhatsappChatMedia $loadMedia,
        private readonly ListWhatsappGroupParticipants $listParticipants,
        private readonly PinWhatsappChat $pinChat,
        private readonly ArchiveWhatsappChat $archiveChat,
        private readonly MarkWhatsappChatUnread $markChatUnread,
        private readonly SetWhatsappChatNickname $setNickname,
        private readonly SetWhatsappChatPriority $setPriority,
    ) {}

    public function slug(): string
    {
        return ChatProvider::WHATSAPP;
    }

    public function capabilities(?ChatConversation $conversation = null): ChatCapabilities
    {
        $target = $conversation === null ? null : $this->targetOf($conversation);

        $capabilities = new ChatCapabilities(
            send: true,
            files: true,
            threads: false,
            reactions: true,
            reactionEmojis: null,
            editMessages: true,
            deleteMessages: true,
            mentions: $target === null || $target->isGroup(),
            markRead: true,
            readReceipts: true,
            history: true,
            externalLink: false,
            formatting: 'whatsapp',
            polls: true,
        );

        if ($target === null) {
            return $capabilities;
        }

        return $this->isWritable($target) ? $capabilities : $capabilities->readOnly();
    }

    public function accountsOf(User $user): Collection
    {
        return WhatsappAccount::query()
            ->where('user_id', $user->id)
            ->withCount(['targets as conversation_count' => fn (Builder $query) => $query->whereNotNull('last_message_at')])
            ->orderBy('nickname')
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsappAccount $account): ChatAccountData => new ChatAccountData(
                id: $account->friendly_id,
                provider: ChatProvider::WHATSAPP,
                label: $account->nickname,
                detail: $account->phone_number,
                isUsable: $account->isOpen(),
                error: $account->isOpen() ? null : $this->accountError($account),
                connectedAt: $account->connected_at === null ? null : CarbonImmutable::instance($account->connected_at),
                syncedAt: $account->chats_synced_at === null ? null : CarbonImmutable::instance($account->chats_synced_at),
                url: route('whatsapp.accounts.index'),
                conversationCount: (int) $account->getAttribute('conversation_count'),
            ))
            ->toBase();
    }

    public function messages(ChatConversation $conversation, ?string $beforeId, int $limit): Collection
    {
        $target = $this->targetOf($conversation);
        $query = $target->chatMessages();

        if ($beforeId !== null) {
            $before = $this->rowOf($target, $beforeId);
            $query->where(fn (Builder $query) => $query
                ->where('sent_at', '<', $before->sent_at)
                ->orWhere(fn (Builder $query) => $query->where('sent_at', $before->sent_at)->where('id', '<', $before->id)));
        }

        $rows = $query->orderByDesc('sent_at')->orderByDesc('id')->limit($limit)->get()->reverse()->values();

        return $this->toData($target, $conversation, $rows);
    }

    public function hasOlderMessages(ChatConversation $conversation, string $oldestId): bool
    {
        $target = $this->targetOf($conversation);
        $oldest = $this->rowOf($target, $oldestId);

        return $target->chatMessages()
            ->where(fn (Builder $query) => $query
                ->where('sent_at', '<', $oldest->sent_at)
                ->orWhere(fn (Builder $query) => $query->where('sent_at', $oldest->sent_at)->where('id', '<', $oldest->id)))
            ->exists();
    }

    public function message(ChatConversation $conversation, string $messageId): ?ChatMessageData
    {
        $target = $this->targetOf($conversation);
        $row = $target->chatMessages()->where('friendly_id', $messageId)->first();

        return $row === null ? null : $this->toData($target, $conversation, new EloquentCollection([$row]))->first();
    }

    /**
     * WhatsApp has no threads: a message answers at most one other, shown
     * as a quote; the "thread" is the message alone.
     */
    public function thread(ChatConversation $conversation, string $messageId): Collection
    {
        $message = $this->message($conversation, $messageId);

        return new Collection($message === null ? [] : [$message]);
    }

    public function participants(ChatConversation $conversation): Collection
    {
        $target = $this->targetOf($conversation);
        $account = $target->account;
        $me = new ChatParticipantData(
            id: self::ownJid($account) ?? 'me',
            name: (string) __('You'),
            handle: self::digitsOf($account->phone_number),
            isMe: true,
        );

        if (! $target->isGroup()) {
            $contact = new ChatParticipantData(
                id: $target->jid,
                name: $target->displayName(),
                handle: self::digitsOf($target->phoneNumber()),
                pictureUrl: $target->picture_url,
            );

            return new Collection([$contact, $me]);
        }

        $members = $account->isOpen()
            ? $this->guard(fn (): array => ($this->listParticipants)($account->user, $target))
            : $this->membersFromMessages($target);

        return (new Collection($members))
            ->map(fn (array $member): ChatParticipantData => new ChatParticipantData(
                id: (string) $member['jid'],
                name: (string) $member['name'],
                handle: self::digitsOf($member['number'] ?? null),
                isMe: $member['me'],
            ))
            ->sortBy(fn (ChatParticipantData $participant): array => [$participant->isMe ? 0 : 1, mb_strtolower($participant->name)])
            ->values();
    }

    public function send(ChatConversation $conversation, OutgoingChatMessage $message): ChatMessageData
    {
        $target = $this->targetOf($conversation);
        $account = $target->account;

        if (! $this->isWritable($target)) {
            throw new ChatProviderException($this->readOnlyReason($target));
        }

        if ($message->isEmpty()) {
            throw new ChatProviderException(__('There is nothing to send.'));
        }

        $delivery = $this->guard(function () use ($target, $account, $message) {
            if ($message->poll !== null) {
                return $this->sendContent->poll($account->user, $target, $message->poll['name'], $message->poll['options'], $message->poll['selectable_count'] !== 1, now: true);
            }

            if ($message->files === []) {
                return ($this->sendMessage)($account->user, $target, $message->text, now: true);
            }

            $delivery = null;

            foreach ($message->files as $index => $file) {
                $upload = new UploadedFile($file->path, $file->name, $file->mime, null, true);
                $delivery = ($this->sendMessage)($account->user, $target, $index === 0 ? $message->text : '', $upload, now: true);
            }

            return $delivery;
        });

        $row = $delivery->external_message_id === null ? null : $target->chatMessages()->where('external_id', $delivery->external_message_id)->first();

        if ($row === null) {
            throw new ChatProviderException($delivery->error_message ?? __('WhatsApp took the message but did not hand it back. Reload the conversation.'));
        }

        return $this->toData($target, $conversation, new EloquentCollection([$row]))->first();
    }

    public function markRead(ChatConversation $conversation): void
    {
        $target = $this->targetOf($conversation);

        if ($target->isUnread()) {
            $target->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
        }

        if (! $target->account->isOpen()) {
            return;
        }

        try {
            ($this->sendReadReceipts)($target->account->user, $target);
        } catch (AuthorizationException $exception) {
            report($exception);
        }
    }

    public function react(ChatConversation $conversation, string $messageId, string $emoji, bool $on): void
    {
        $target = $this->targetOf($conversation);
        $row = $this->rowOf($target, $messageId);

        if (! $this->isWritable($target)) {
            throw new ChatProviderException($this->readOnlyReason($target));
        }

        // The Action toggles: the emoji already there comes off, another one replaces it.
        $current = $row->reactions()->where('reactor', WhatsappChatReaction::ME)->value('emoji');

        if ($on === ($current === $emoji)) {
            return;
        }

        $this->guard(fn () => ($this->sendReaction)($target->account->user, $row, $emoji));
    }

    public function edit(ChatConversation $conversation, string $messageId, string $text): ChatMessageData
    {
        $target = $this->targetOf($conversation);
        $row = $this->rowOf($target, $messageId);

        if (! $this->isWritable($target)) {
            throw new ChatProviderException($this->readOnlyReason($target));
        }

        $edited = $this->guard(fn (): WhatsappChatMessage => ($this->editMessage)($target->account->user, $row, $text));

        return $this->toData($target, $conversation, new EloquentCollection([$edited]))->first();
    }

    public function delete(ChatConversation $conversation, string $messageId): void
    {
        $target = $this->targetOf($conversation);
        $row = $this->rowOf($target, $messageId);

        if (! $this->isWritable($target)) {
            throw new ChatProviderException($this->readOnlyReason($target));
        }

        $this->guard(fn () => ($this->deleteMessage)($target->account->user, $row));
    }

    /**
     * Asks the Evolution API for the chat's name and picture and its latest
     * messages (SyncWhatsappChat): the same whether or not `$olderThanId`
     * is given, as the API hands the history over by pages from the latest.
     */
    public function refresh(ChatConversation $conversation, ?string $olderThanId = null): void
    {
        $target = $this->targetOf($conversation);
        $account = $target->account;

        if (! $account->isOpen()) {
            throw new ChatProviderException($this->accountError($account));
        }

        $this->guard(fn () => ($this->syncChat)($account->user, $target));
    }

    public function file(ChatConversation $conversation, string $messageId, string $fileId): ?ChatFileContents
    {
        $target = $this->targetOf($conversation);
        $row = $this->rowOf($target, $messageId);

        if ($fileId !== self::FILE_ID || $row->mediaKind() === null) {
            return null;
        }

        $row->setRelation('target', $target);
        $loaded = $this->guard(fn (): bool => ($this->loadMedia)($target->account->user, $row));

        if (! $loaded || $row->media_path === null) {
            return null;
        }

        WhatsappChatMedia::touch($row);

        return new ChatFileContents(
            contents: (string) WhatsappChatMedia::disk()->get($row->media_path),
            name: $row->media_name ?? $row->summary()['label'],
            mime: $row->media_mime ?? 'application/octet-stream',
        );
    }

    public function styleSamples(User $user, ChatConversation $except, int $limit): array
    {
        $exceptId = $except->conversable_type === (new WhatsappTarget)->getMorphClass() ? $except->conversable_id : null;

        $samples = WhatsappChatMessage::query()
            ->select('whatsapp_chat_messages.body')
            ->join('whatsapp_targets', 'whatsapp_targets.id', '=', 'whatsapp_chat_messages.whatsapp_target_id')
            ->join('whatsapp_accounts', 'whatsapp_accounts.id', '=', 'whatsapp_targets.whatsapp_account_id')
            ->where('whatsapp_accounts.user_id', $user->id)
            ->where('whatsapp_chat_messages.from_me', true)
            ->whereIn('whatsapp_chat_messages.message_type', self::TEXT_TYPES)
            ->whereNull('whatsapp_chat_messages.revoked_at')
            ->whereNotNull('whatsapp_chat_messages.body')
            ->where('whatsapp_chat_messages.body', '!=', '')
            ->when($exceptId !== null, fn (Builder $query) => $query->where('whatsapp_targets.id', '!=', $exceptId))
            ->orderByDesc('whatsapp_chat_messages.sent_at')
            ->limit($limit)
            ->pluck('whatsapp_chat_messages.body')
            ->map(fn (string $body): string => trim($body))
            ->filter()
            ->values()
            ->all();

        return array_values($samples);
    }

    /**
     * A WhatsApp chat has no address of its own to open.
     */
    public function externalUrl(ChatConversation $conversation): ?string
    {
        return null;
    }

    /**
     * Writes what the user set on the entry onto the target through the
     * WhatsApp Actions, so the WhatsApp screen, the apps and the phone see
     * it: the pin, the archive (told to the phone), the mute, the unread
     * mark (told to the phone), the nickname, the priority and what the AI
     * found about it. Within a change of many entries at once
     * (ApplyChatConversationsBulkAction), the WhatsApp screens are not told
     * of each chat.
     */
    public function entryChanged(ChatConversation $conversation, array $changes): void
    {
        $target = $this->targetOf($conversation);
        $user = $target->account->user;
        $announce = ! ApplyChatConversationsBulkAction::isRunning();

        $this->guard(function () use ($target, $user, $changes, $announce): void {
            if (array_key_exists('pinned_at', $changes)) {
                ($this->pinChat)($user, $target, $changes['pinned_at'] !== null);
            }

            if (array_key_exists('archived_at', $changes)) {
                ($this->archiveChat)($user, $target, $changes['archived_at'] !== null, $announce);
            }

            if (array_key_exists('muted_until', $changes)) {
                $target->update(['muted_until' => $changes['muted_until']]);
            }

            if (array_key_exists('marked_unread', $changes)) {
                ($this->markChatUnread)($user, $target, (bool) $changes['marked_unread'], $announce);
            }

            if (array_key_exists('nickname', $changes)) {
                ($this->setNickname)($user, $target, $changes['nickname']);
            }

            if (array_key_exists('priority', $changes)) {
                ($this->setPriority)($user, $target, $changes['priority']);
            }

            $triage = array_intersect_key($changes, array_flip(WhatsappTarget::AI_TRIAGE_COLUMNS));

            if ($triage !== []) {
                $target->forceFill($triage)->save();
            }
        });
    }

    /**
     * The rows as the screens take them: the names of who is mentioned or
     * quoted looked up once (WhatsappChatMessage::withContext()), the
     * pictures of a group's senders from the contacts.
     *
     * @param  EloquentCollection<int, WhatsappChatMessage>  $rows
     * @return Collection<int, ChatMessageData>
     */
    private function toData(WhatsappTarget $target, ChatConversation $entry, EloquentCollection $rows): Collection
    {
        $rows = WhatsappChatMessage::withContext($rows, $target);
        $rows->loadMissing('reactions');

        $writable = $this->isWritable($target);
        $senderJids = array_values(array_filter($rows->pluck('sender_jid')->unique()->all(), 'is_string'));
        $pictures = $target->isGroup() ? LoadWhatsappSenderPictures::picturesOf($target, $senderJids) : [];
        $reactorNames = $this->namesOf($target, array_values(array_filter($rows->flatMap(fn (WhatsappChatMessage $row): array => $row->reactions->pluck('reactor')->all())->unique()->all(), 'is_string')));

        return $rows->map(function (WhatsappChatMessage $row) use ($target, $entry, $writable, $pictures, $reactorNames): ChatMessageData {
            $summary = $row->summary();
            $isText = in_array($row->message_type, self::TEXT_TYPES, true);
            $file = $this->fileOf($entry, $row);
            $text = filled($row->body) ? $row->bodyWithMentions() : ($isText || $file !== null ? null : $summary['label']);

            return new ChatMessageData(
                id: $row->friendly_id,
                conversationId: $entry->friendly_id,
                fromMe: $row->from_me,
                senderId: $row->from_me ? null : ($row->sender_jid ?? $target->jid),
                senderName: $row->from_me ? null : ($row->senderLabel() ?? $target->displayName()),
                senderPictureUrl: $row->from_me ? null : ($target->isGroup() ? ($pictures[$row->sender_jid ?? ''] ?? null) : $target->picture_url),
                kind: $file === null ? 'text' : 'file',
                text: $text === '' ? null : $text,
                html: filled($row->body) ? $row->bodyHtml()->toHtml() : '',
                files: $file === null ? [] : [$file],
                threadId: null,
                replyCount: 0,
                reactions: $this->reactionsOf($row, $reactorNames),
                sentAt: CarbonImmutable::instance($row->sent_at),
                editedAt: $row->edited_at === null ? null : CarbonImmutable::instance($row->edited_at),
                deletedAt: $row->revoked_at === null ? null : CarbonImmutable::instance($row->revoked_at),
                canEdit: $writable && $row->canEdit(),
                canDelete: $writable && $row->canDeleteForEveryone(),
            );
        })->toBase();
    }

    private function fileOf(ChatConversation $entry, WhatsappChatMessage $row): ?ChatFileData
    {
        $kind = $row->mediaKind();

        if ($kind === null || $row->isRevoked()) {
            return null;
        }

        return new ChatFileData(
            id: self::FILE_ID,
            name: $row->media_name ?? $row->summary()['label'],
            mime: $row->media_mime,
            size: $row->media_size,
            kind: $kind,
            url: $row->canDownloadMedia() ? $this->fileUrl($entry, $row) : null,
            thumbnailUrl: $row->thumbnailUri(),
        );
    }

    /**
     * A temporary signed link to the file, good for the web and the apps.
     */
    private function fileUrl(ChatConversation $entry, WhatsappChatMessage $row): string
    {
        return URL::temporarySignedRoute('api.chats.file', now()->addMinutes(self::FILE_URL_MINUTES), [
            'conversation' => $entry->friendly_id,
            'message' => $row->friendly_id,
            'file' => self::FILE_ID,
        ]);
    }

    /**
     * @param  array<string, string>  $names  reactor => name
     * @return list<ChatReactionData>
     */
    private function reactionsOf(WhatsappChatMessage $row, array $names): array
    {
        $reactions = $row->reactions
            ->groupBy('emoji')
            ->map(fn (EloquentCollection $reactions, string $emoji): ChatReactionData => new ChatReactionData(
                emoji: $emoji,
                count: $reactions->count(),
                mine: $reactions->contains(fn (WhatsappChatReaction $reaction): bool => $reaction->isMine()),
                names: array_values($reactions->map(fn (WhatsappChatReaction $reaction): string => $names[$reaction->reactor] ?? $reaction->reactor)->all()),
            ))
            ->sortByDesc('count')
            ->values()
            ->all();

        return array_values($reactions);
    }

    /**
     * The names of the people with those JIDs (`me` for the owner), from
     * the account's contacts; a number for the ones not known.
     *
     * @param  list<string>  $jids
     * @return array<string, string>
     */
    private function namesOf(WhatsappTarget $target, array $jids): array
    {
        $jids = array_values(array_filter($jids, fn (string $jid): bool => $jid !== WhatsappChatReaction::ME));
        $names = [WhatsappChatReaction::ME => (string) __('You')];

        if ($jids === []) {
            return $names;
        }

        $contacts = WhatsappTarget::query()
            ->where('whatsapp_account_id', $target->whatsapp_account_id)
            ->where(fn (Builder $query) => $query->whereIn('jid', $jids)->orWhereIn('lid', $jids))
            ->get();

        foreach ($jids as $jid) {
            $contact = $contacts->first(fn (WhatsappTarget $contact): bool => $contact->jid === $jid || $contact->lid === $jid);
            $names[$jid] = $contact?->displayName() ?? (str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.strstr($jid, '@', true) : $jid);
        }

        return $names;
    }

    /**
     * Who wrote in a group, when the number is not connected to ask
     * WhatsApp for its members.
     *
     * @return list<array{jid: string, name: string, number: string|null, me: bool}>
     */
    private function membersFromMessages(WhatsappTarget $target): array
    {
        $jids = array_values(array_filter($target->chatMessages()->whereNotNull('sender_jid')->distinct()->pluck('sender_jid')->all(), 'is_string'));
        $names = $this->namesOf($target, $jids);

        $members = array_map(fn (string $jid): array => [
            'jid' => $jid,
            'name' => $names[$jid] ?? $jid,
            'number' => str_ends_with($jid, WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.strstr($jid, '@', true) : null,
            'me' => false,
        ], $jids);

        $own = self::ownJid($target->account);

        if ($own !== null) {
            $members[] = ['jid' => $own, 'name' => (string) __('You'), 'number' => '+'.self::digitsOf($target->account->phone_number), 'me' => true];
        }

        return $members;
    }

    private static function ownJid(WhatsappAccount $account): ?string
    {
        $digits = self::digitsOf($account->phone_number);

        return $digits === null ? null : $digits.WhatsappTarget::CONTACT_JID_SUFFIX;
    }

    private static function digitsOf(?string $number): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $number);

        return $digits === '' || $digits === null ? null : $digits;
    }

    private function isWritable(WhatsappTarget $target): bool
    {
        return $target->account->isOpen() && ! $target->isBlocked();
    }

    private function readOnlyReason(WhatsappTarget $target): string
    {
        return $target->account->isOpen()
            ? __('You blocked this contact on WhatsApp.')
            : $this->accountError($target->account);
    }

    private function accountError(WhatsappAccount $account): string
    {
        return __('The WhatsApp number :nickname is not connected. Reconnect it on the WhatsApp screen.', ['nickname' => $account->nickname]);
    }

    /**
     * The WhatsappTarget of an entry of the chat screen.
     */
    private function targetOf(ChatConversation $conversation): WhatsappTarget
    {
        $target = $conversation->conversable;

        if (! $target instanceof WhatsappTarget) {
            throw new InvalidArgumentException('The conversation is not a WhatsApp one.');
        }

        $target->loadMissing('account.user');
        $target->setRelation('conversation', $conversation);

        return $target;
    }

    /**
     * @throws ChatProviderException for a message id not of the chat
     */
    private function rowOf(WhatsappTarget $target, string $messageId): WhatsappChatMessage
    {
        $row = $target->chatMessages()->where('friendly_id', $messageId)->first();

        if ($row === null) {
            throw new ChatProviderException(__('This message is no longer here.'));
        }

        $row->setRelation('target', $target);

        return $row;
    }

    /**
     * Runs a WhatsApp Action, turning what it refuses (a validation
     * message, written for the user) or what the Evolution API failed into
     * one the screens show.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws ChatProviderException
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $exception) {
            $messages = $exception->validator->errors()->all();

            throw new ChatProviderException((string) ($messages[0] ?? $exception->getMessage()), $exception);
        } catch (AuthorizationException $exception) {
            throw new ChatProviderException(__('WhatsApp is not switched on for you.'), $exception);
        } catch (EvolutionApiException $exception) {
            report($exception);

            throw new ChatProviderException(__('WhatsApp could not do that right now. Try again in a moment.'), $exception);
        }
    }
}
