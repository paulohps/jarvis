<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\ApplyWhatsappChatsBulkAction;
use App\Actions\Whatsapp\ArchiveWhatsappChat;
use App\Actions\Whatsapp\CancelWhatsappMessage;
use App\Actions\Whatsapp\CreateWhatsappChatReminder;
use App\Actions\Whatsapp\DeleteWhatsappChatMessageForEveryone;
use App\Actions\Whatsapp\DeleteWhatsappTag;
use App\Actions\Whatsapp\DraftWhatsappReply;
use App\Actions\Whatsapp\EditWhatsappChatMessage;
use App\Actions\Whatsapp\ForwardWhatsappChatMessages;
use App\Actions\Whatsapp\ListWhatsappGroupParticipants;
use App\Actions\Whatsapp\LoadWhatsappChatMedia;
use App\Actions\Whatsapp\LoadWhatsappSenderPictures;
use App\Actions\Whatsapp\MarkWhatsappChatUnread;
use App\Actions\Whatsapp\MuteWhatsappChat;
use App\Actions\Whatsapp\PinWhatsappChat;
use App\Actions\Whatsapp\RefreshWhatsappChatPicture;
use App\Actions\Whatsapp\RequestWhatsappChatTriage;
use App\Actions\Whatsapp\SaveWhatsappTag;
use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\SendWhatsappChatMessage;
use App\Actions\Whatsapp\SendWhatsappReaction;
use App\Actions\Whatsapp\SendWhatsappReadReceipts;
use App\Actions\Whatsapp\SetWhatsappChatNickname;
use App\Actions\Whatsapp\SetWhatsappChatNotifications;
use App\Actions\Whatsapp\SetWhatsappChatPriority;
use App\Actions\Whatsapp\SetWhatsappChatPrivacy;
use App\Actions\Whatsapp\SetWhatsappChatTags;
use App\Actions\Whatsapp\ShowWhatsappTyping;
use App\Actions\Whatsapp\StartWhatsappChat;
use App\Actions\Whatsapp\StarWhatsappChatMessage;
use App\Actions\Whatsapp\SyncWhatsappChat;
use App\Actions\Whatsapp\WatchWhatsappChatPresence;
use App\Events\NotificationCreated;
use App\Exceptions\AiProviderNotConfigured;
use App\Exceptions\EvolutionApiException;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\DeliveryStatus;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappDraftMessage;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTag;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The WhatsApp screen: the chats of one of the user's numbers, newest
 * first, beside the open chat, full window in a tab of its own (layout
 * `chat`, without the panel).
 *
 * - The list has the chats, one-to-one and groups, that had a message (kept
 *   by RecordWhatsappChatMessages), with the last message and how many arrived
 *   unread; search goes by name or number. Opening a chat marks it read.
 *   The unread count is Jarvis' own: reading on the phone does not clear it.
 * - The chat shows the latest MESSAGES_PER_PAGE messages, with who sent
 *   each in a group, and loads older ones on demand. A media shows as its
 *   kind, name and size until "Load" brings the file in
 *   (LoadWhatsappChatMedia); then an image, a video or an audio shows in
 *   place, and any file can be downloaded (WhatsappChatMediaController).
 *   What is typed,
 *   with a file attached or not, goes out through SendWhatsappChatMessage
 *   and shows in the chat once sent; meanwhile, and when it fails, it shows
 *   under the messages.
 * - What the number sent shows WhatsApp's ticks (x-whatsapp.receipt), and
 *   "Sync this chat" (SyncWhatsappChat) brings the open chat's name,
 *   picture, messages and ticks in line with WhatsApp.
 * - "New chat" opens the chat with a number typed in international format
 *   (StartWhatsappChat).
 * - Everything follows without a refresh: `WhatsappChatUpdated` on the
 *   user's private channel reloads the list and the open chat.
 *
 * What WhatsApp lacks:
 *
 * - any number of chats pinned on top (PinWhatsappChat), the latest pinned
 *   first;
 * - a priority from 1 to 5 with a color (SetWhatsappChatPriority), shown on
 *   the chat, and the list sorted by it on demand (`sort=priority`: pinned,
 *   then by priority, then newest);
 * - reminders from the chat or from a message (CreateWhatsappChatReminder),
 *   the open ones listed in the chat;
 * - a message written with the AI in the user's style, in a conversation
 *   with it in a side panel (DraftAssistant, `whatsapp-draft` with the
 *   messages picked for it to answer), into the text being typed
 *   (`whatsapp-use-draft`);
 * - unread chats picked in the list prioritized by the AI in the background
 *   (RequestWhatsappChatTriage): each shows how urgent it found it and
 *   what it wants, out of date once a newer message comes and gone once
 *   the chat is read, and the list sorts by it (`sort=ai`), apart from
 *   the user's own priority;
 * - chats picked in the list changed at once (ApplyWhatsappChatsBulkAction):
 *   read, unread, archived, muted, their priority or the AI's cleared;
 * - messages scheduled to the chat (ScheduleWhatsappMessage), once or
 *   repeating (the recurrence picker), with a text written beforehand or
 *   written by the AI from a prompt when each one goes out; listed in the
 *   chat until sent, a `once` one cancelled there (CancelWhatsappMessage),
 *   a repeating one edited on its page.
 *
 * @property-read Collection<int, WhatsappAccount> $accounts
 * @property-read WhatsappAccount|null $account
 * @property-read Collection<int, WhatsappTarget> $chats
 * @property-read WhatsappTarget|null $chat
 * @property-read Collection<int, WhatsappChatMessage> $messages
 * @property-read Collection<int, WhatsappDelivery> $outgoing
 * @property-read Collection<int, WhatsappMessage> $scheduled
 * @property-read Collection<int, Reminder> $chatReminders
 */
#[Layout('layouts::chat')]
class Chat extends Component
{
    use WithFileUploads;

    public const int MESSAGES_PER_PAGE = 50;

    public const int CHATS_SHOWN = 200;

    public const string SORT_RECENT = 'recent';

    public const string SORT_PRIORITY = 'priority';

    /** By the urgency the AI found (RequestWhatsappChatTriage), apart from the user's priority. */
    public const string SORT_AI = 'ai';

    /**
     * A chat setting that follows the one of its number.
     */
    public const string FOLLOW_ACCOUNT = 'account';

    #[Locked]
    public int $userId = 0;

    /**
     * The friendly id of the account shown.
     */
    #[Url(as: 'account')]
    public ?string $accountId = null;

    /**
     * The friendly id of the open chat.
     */
    #[Url(as: 'chat')]
    public ?string $chatId = null;

    public string $search = '';

    /**
     * How the list is sorted after the pinned chats: `recent`, `priority`
     * or `ai`.
     */
    #[Url(as: 'sort', except: self::SORT_RECENT)]
    public string $sort = self::SORT_RECENT;

    /**
     * The friendly id of the tag the list is filtered by, if any.
     */
    #[Url(as: 'tag', except: '')]
    public string $tagFilter = '';

    public bool $showTags = false;

    /** Whether the list shows the archived chats instead of the others. */
    public bool $showArchived = false;

    /**
     * The friendly ids of the tags picked for the open chat.
     *
     * @var list<string>
     */
    public array $chatTags = [];

    /**
     * The tag being created or edited.
     */
    #[Locked]
    public ?string $editingTagId = null;

    public string $tagName = '';

    public string $tagColor = 'blue';

    public string $text = '';

    /**
     * The file attached to the message being typed.
     */
    public ?TemporaryUploadedFile $attachment = null;

    /** A voice note recorded in the composer, sent as soon as it is uploaded (sendVoice()). */
    public ?TemporaryUploadedFile $voiceNote = null;

    /** The file a scheduled message sends: attached, or a voice note recorded in the browser. */
    public ?TemporaryUploadedFile $scheduleFile = null;

    public int $messageLimit = self::MESSAGES_PER_PAGE;

    public bool $showNewChat = false;

    public string $number = '';

    public ?string $notice = null;

    public ?string $error = null;

    public bool $showPriority = false;

    public bool $showNickname = false;

    public string $nickname = '';

    public string $priority = '';

    public bool $showSettings = false;

    /**
     * What the number tells WhatsApp, for its chats by default.
     *
     * @var array{read_receipts: bool, typing: bool, notifications: bool, phone_reads: bool}
     */
    public array $accountPrivacy = ['read_receipts' => false, 'typing' => false, 'notifications' => true, 'phone_reads' => true];

    /**
     * What the open chat tells WhatsApp: `on`, `off`, or `account` to follow
     * its number.
     *
     * @var array{read_receipts: string, typing: string, notifications: string}
     */
    public array $chatPrivacy = ['read_receipts' => self::FOLLOW_ACCOUNT, 'typing' => self::FOLLOW_ACCOUNT, 'notifications' => self::FOLLOW_ACCOUNT];

    public bool $showReminder = false;

    /**
     * The friendly id of the message the reminder is about, if any.
     */
    public ?string $reminderMessageId = null;

    public string $reminderTitle = '';

    public string $reminderDueAt = '';

    public bool $showSchedule = false;

    public RecurrencePicker $scheduleRecurrence;

    /** Whether the AI writes the scheduled message when it goes out, from `schedulePrompt`. */
    public bool $scheduleWithAi = false;

    public string $schedulePrompt = '';

    /**
     * The friendly ids of the messages picked for the AI to answer
     * together; none answers the last one the contact sent.
     *
     * @var list<string>
     */
    public array $selected = [];

    /**
     * The message the AI wrote that went into the composer (DraftAssistant),
     * so that the message sent leads back to its conversation.
     */
    #[Locked]
    public ?string $draftMessageId = null;

    public bool $showForward = false;

    /**
     * The messages being forwarded, by friendly id.
     *
     * @var list<string>
     */
    public array $forwardIds = [];

    /**
     * The chats they go to, by friendly id.
     *
     * @var list<string>
     */
    public array $forwardTo = [];

    public string $forwardSearch = '';

    /** Whether the side panel of the open chat shows (ChatInfo). */
    public bool $showInfo = false;

    /** The tab the side panel opens on (ChatInfo::TABS). */
    public string $infoTab = ChatInfo::TAB_SEARCH;

    /**
     * The message just shown from the side panel, to scroll to and flash.
     */
    public ?string $highlightId = null;

    /** The message of the open chat the next one answers (quoted). */
    #[Locked]
    public ?string $replyToId = null;

    public bool $showEdit = false;

    #[Locked]
    public ?string $editingMessageId = null;

    public string $editText = '';

    public bool $showDeleteForEveryone = false;

    #[Locked]
    public ?string $deletingMessageId = null;

    public bool $showMessageInfo = false;

    #[Locked]
    public ?string $infoMessageId = null;

    /**
     * The members of the open group the composer can mention, once asked
     * for (loadMentionables()): name and number.
     *
     * @var list<array{name: string, number: string}>
     */
    public array $mentionables = [];

    /**
     * Whether the chat list (the `chats` island, which a render of the
     * screen skips) renders too, after the screen (refreshChatList()).
     */
    protected bool $chatListChanged = false;

    public function mount(WatchWhatsappChatPresence $watchPresence): void
    {
        $this->userId = $this->user()->id;

        if ($this->chat !== null) {
            $this->markRead($this->chat);
            $watchPresence($this->user(), $this->chat);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-private:'.NotificationCreated::channelFor($this->userId);

        return [
            "{$channel},.whatsapp.chat.updated" => 'chatUpdated',
            "{$channel},.whatsapp.chats.triaged" => 'chatsTriaged',
        ];
    }

    /**
     * @return Collection<int, WhatsappAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->user()->whatsappAccounts()->orderBy('nickname')->orderBy('id')->get();
    }

    #[Computed]
    public function account(): ?WhatsappAccount
    {
        return $this->accounts->firstWhere('friendly_id', $this->accountId) ?? $this->accounts->first();
    }

    /**
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function chats(): Collection
    {
        if ($this->account === null) {
            return new Collection;
        }

        $search = mb_trim($this->search);

        return $this->account->targets()
            ->where(fn (Builder $query) => $query->whereNotNull('last_message_at')->orWhereNotNull('pinned_at'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('nickname', "%{$search}%")
                ->orWhereLike('jid', '%'.ltrim($search, '+').'%')))
            ->when($this->tagFilter !== '', fn (Builder $query) => $query->whereHas('tags', fn (Builder $query) => $query->where('friendly_id', $this->tagFilter)))
            ->when($search === '', fn (Builder $query) => $this->showArchived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at'))
            ->with(['lastChatMessage', 'tags'])
            ->orderByRaw('pinned_at is null')
            ->orderByDesc('pinned_at')
            ->when($this->sort === self::SORT_PRIORITY, fn (Builder $query) => $query->orderByRaw('priority is null')->orderBy('priority'))
            ->when($this->sort === self::SORT_AI, fn (Builder $query) => $query->orderByRaw('ai_urgency is null')->orderBy('ai_urgency'))
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::CHATS_SHOWN)
            ->get();
    }

    /**
     * How many chats of the number are archived, for the "Archived" row.
     */
    #[Computed]
    public function archivedCount(): int
    {
        return $this->account?->targets()->whereNotNull('archived_at')->count() ?? 0;
    }

    #[Computed]
    public function chat(): ?WhatsappTarget
    {
        if ($this->account === null || $this->chatId === null) {
            return null;
        }

        return $this->account->targets()->where('friendly_id', $this->chatId)->first();
    }

    /**
     * The latest messages of the open chat, oldest first, plus one more when
     * there are older ones (see hasOlderMessages()).
     *
     * @return Collection<int, WhatsappChatMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        if ($this->chat === null) {
            return new Collection;
        }

        return WhatsappChatMessage::withContext($this->chat->chatMessages()
            ->with(['reactions', 'pollVotes'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit($this->messageLimit + 1)
            ->get()
            ->reverse()
            ->values(), $this->chat);
    }

    /**
     * The pictures of who sent the shown messages of an open group
     * (LoadWhatsappSenderPictures::picturesOf()): URL, empty string for
     * none, null while not asked yet.
     *
     * @return array<string, string|null>
     */
    #[Computed]
    public function senderPictures(): array
    {
        if ($this->chat === null || ! $this->chat->isGroup()) {
            return [];
        }

        return LoadWhatsappSenderPictures::picturesOf($this->chat, $this->shownSenderJids());
    }

    /**
     * Called once a group shows while some of its senders' pictures were
     * not asked yet, so opening it never waits on WhatsApp.
     */
    public function loadSenderPictures(LoadWhatsappSenderPictures $loadPictures): void
    {
        if ($this->chat === null) {
            return;
        }

        $loadPictures($this->user(), $this->chat, $this->shownSenderJids());
        unset($this->senderPictures);
    }

    /**
     * What was sent from this screen to the open chat and did not go out
     * yet, or failed, in the last day.
     *
     * @return Collection<int, WhatsappDelivery>
     */
    #[Computed]
    public function outgoing(): Collection
    {
        if ($this->chat === null) {
            return new Collection;
        }

        return WhatsappDelivery::query()
            ->with('status')
            ->where('whatsapp_target_id', $this->chat->id)
            ->where('whatsapp_delivery_source_id', WhatsappDeliverySource::idFor(WhatsappDeliverySource::CHAT))
            ->whereIn('delivery_status_id', [DeliveryStatus::idFor(DeliveryStatus::PENDING), DeliveryStatus::idFor(DeliveryStatus::FAILED)])
            ->where('created_at', '>=', now()->subDay())
            ->oldest()
            ->get();
    }

    /**
     * The user's messages scheduled to the open chat that did not go out
     * yet, the next first.
     *
     * @return Collection<int, WhatsappMessage>
     */
    #[Computed]
    public function scheduled(): Collection
    {
        if ($this->chat === null) {
            return new Collection;
        }

        return WhatsappMessage::query()
            ->where('user_id', $this->userId)
            ->whereNull('cancelled_at')
            ->whereHas('targets', fn (Builder $query) => $query->whereKey($this->chat->id))
            ->whereHas('recurrence', fn (Builder $query) => $query->whereNotNull('next_run_at')->where('is_paused', false))
            ->with('recurrence')
            ->get()
            ->sortBy(fn (WhatsappMessage $message) => $message->recurrence?->next_run_at)
            ->values();
    }

    /**
     * The reminders created from the open chat that are still open, with
     * their next occurrence.
     *
     * @return Collection<int, Reminder>
     */
    #[Computed]
    public function chatReminders(): Collection
    {
        if ($this->chat === null) {
            return new Collection;
        }

        return $this->chat->reminders()
            ->visibleTo($this->user())
            ->whereHas('occurrences', fn (Builder $query) => $query->whereNull('completed_at'))
            ->with(['occurrences' => fn (HasMany $query) => $query->whereNull('completed_at')->orderBy('due_at')])
            ->orderBy('reminders.id')
            ->get();
    }

    public function hasOlderMessages(): bool
    {
        return $this->messages->count() > $this->messageLimit;
    }

    /**
     * The search, the sort and the archived toggle change the list. From
     * the list itself only its island renders anyway; from elsewhere it is
     * asked for.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort', 'tagFilter', 'showArchived'], true)) {
            $this->refreshChatList();
        }
    }

    public function selectAccount(string $accountId): void
    {
        $this->accountId = $this->accounts->firstWhere('friendly_id', $accountId)?->friendly_id;
        $this->reset('chatId', 'search', 'text', 'messageLimit', 'selected');
        $this->resetErrorBag();
        unset($this->account, $this->chat, $this->messages, $this->outgoing);
        $this->refreshChatList();
    }

    public function openChat(string $chatId, WatchWhatsappChatPresence $watchPresence): void
    {
        $this->chatId = $chatId;
        $this->reset('text', 'messageLimit', 'notice', 'error', 'selected', 'showInfo', 'highlightId', 'replyToId', 'mentionables', 'draftMessageId');
        $this->resetErrorBag();
        unset($this->chat, $this->messages, $this->outgoing, $this->scheduled, $this->chatReminders);

        if ($this->chat === null) {
            $this->chatId = null;

            return;
        }

        $wasUnread = $this->chat->unread_count > 0 || $this->chat->marked_unread;
        $this->markRead($this->chat);

        if ($wasUnread) {
            $this->refreshChatList();
        }

        $watchPresence($this->user(), $this->chat);
        $this->dispatch('whatsapp-chat-opened');
    }

    public function closeChat(): void
    {
        $this->reset('chatId', 'text', 'messageLimit', 'notice', 'error', 'selected', 'showInfo', 'highlightId', 'replyToId', 'mentionables', 'draftMessageId');
    }

    /**
     * Opens the side panel of the open chat on a tab.
     */
    public function openInfo(string $tab = ChatInfo::TAB_SEARCH): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->infoTab = in_array($tab, ChatInfo::TABS, true) ? $tab : ChatInfo::TAB_SEARCH;
        $this->showInfo = true;
        $this->dispatch('whatsapp-info-tab', tab: $this->infoTab);
    }

    /**
     * Shows a message picked in the side panel: loads the chat as far back
     * as it is, scrolls to it and flashes it.
     */
    #[On('whatsapp-show-message')]
    public function showMessage(string $messageId): void
    {
        $message = $this->chat?->chatMessages()->where('friendly_id', $messageId)->first();

        if ($message === null) {
            return;
        }

        $newer = $this->chat->chatMessages()
            ->where(fn (Builder $query) => $query->where('sent_at', '>', $message->sent_at)
                ->orWhere(fn (Builder $query) => $query->where('sent_at', $message->sent_at)->where('id', '>=', $message->id)))
            ->count();

        $this->messageLimit = max($this->messageLimit, (int) ceil(($newer + 5) / self::MESSAGES_PER_PAGE) * self::MESSAGES_PER_PAGE);
        $this->highlightId = $messageId;
        $this->showInfo = false;
        unset($this->messages);
        $this->dispatch('whatsapp-scroll-to', messageId: $messageId);
    }

    #[On('whatsapp-message-changed')]
    public function messageChanged(): void
    {
        unset($this->messages);
    }

    /**
     * The message the next one answers, while it is of the open chat.
     */
    #[Computed]
    public function replyTo(): ?WhatsappChatMessage
    {
        if ($this->replyToId === null || $this->chat === null) {
            return null;
        }

        $message = $this->chat->chatMessages()->where('friendly_id', $this->replyToId)->first();

        return $message?->setRelation('target', $this->chat);
    }

    /**
     * Answers a message of the open chat with the next one sent, quoted.
     */
    public function reply(string $messageId): void
    {
        $this->replyToId = $this->chatMessage($messageId)->friendly_id;
        unset($this->replyTo);
        $this->dispatch('whatsapp-chat-drafted');
    }

    public function cancelReply(): void
    {
        $this->reset('replyToId');
    }

    /**
     * A poll, location, contacts or sticker is on its way (ChatContent):
     * it answers no message anymore, and shows under the messages.
     */
    #[On('whatsapp-poll-scheduled')]
    public function pollScheduled(): void
    {
        $this->reset('replyToId');
        $this->notice = __('Poll scheduled.');
        unset($this->scheduled);
    }

    #[On('whatsapp-content-sent')]
    public function contentSent(): void
    {
        $this->reset('replyToId');
        unset($this->outgoing);
        $this->dispatch('whatsapp-chat-opened');
    }

    /**
     * The members of the open group, for the composer to mention them.
     */
    public function loadMentionables(ListWhatsappGroupParticipants $listParticipants): void
    {
        if ($this->chat === null || ! $this->chat->isGroup() || ! $this->account?->isOpen()) {
            return;
        }

        try {
            $members = $listParticipants($this->user(), $this->chat);
        } catch (EvolutionApiException $exception) {
            report($exception);

            return;
        }

        $this->mentionables = array_values(array_map(
            fn (array $member): array => ['name' => $member['name'], 'number' => ltrim((string) $member['number'], '+')],
            array_filter($members, fn (array $member): bool => ! $member['me'] && $member['number'] !== null),
        ));
    }

    public function startEdit(string $messageId): void
    {
        $message = $this->chatMessage($messageId);

        $this->resetErrorBag();
        $this->editingMessageId = $message->friendly_id;
        $this->editText = (string) $message->body;
        $this->showEdit = true;
    }

    public function saveEdit(EditWhatsappChatMessage $editMessage): void
    {
        if ($this->editingMessageId === null) {
            return;
        }

        $this->clearMessages();

        try {
            $editMessage($this->user(), $this->chatMessage($this->editingMessageId), $this->editText);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->addError('editText', __('WhatsApp did not take the edit. Try again in a moment.'));

            return;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['editText' => $exception->errors()['text'] ?? []]);
        }

        $this->reset('showEdit', 'editingMessageId', 'editText');
        unset($this->messages);
    }

    public function confirmDeleteForEveryone(string $messageId): void
    {
        $this->deletingMessageId = $this->chatMessage($messageId)->friendly_id;
        $this->showDeleteForEveryone = true;
    }

    public function deleteForEveryone(DeleteWhatsappChatMessageForEveryone $deleteMessage): void
    {
        if ($this->deletingMessageId === null) {
            return;
        }

        $this->clearMessages();

        try {
            $deleteMessage($this->user(), $this->chatMessage($this->deletingMessageId));
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp did not delete it. Try again in a moment.');
        }

        $this->reset('showDeleteForEveryone', 'deletingMessageId');
        unset($this->messages);
    }

    public function toggleStar(string $messageId, StarWhatsappChatMessage $star): void
    {
        $message = $this->chatMessage($messageId);

        $star($this->user(), $message, $message->starred_at === null);
        unset($this->messages);
    }

    /**
     * Opens the info of a message the number sent: when it reached the
     * contact and when they read or played it.
     */
    public function openMessageInfo(string $messageId): void
    {
        $this->infoMessageId = $this->chatMessage($messageId)->friendly_id;
        unset($this->infoMessage);
        $this->showMessageInfo = true;
    }

    #[Computed]
    public function infoMessage(): ?WhatsappChatMessage
    {
        if ($this->infoMessageId === null || $this->chat === null) {
            return null;
        }

        return $this->chat->chatMessages()->where('friendly_id', $this->infoMessageId)->with('receipts')->first()?->setRelation('target', $this->chat);
    }

    /**
     * A new group was created (NewGroup): it opens.
     */
    #[On('whatsapp-open-chat')]
    public function openCreatedChat(string $chatId): void
    {
        $this->refreshChatList();
        $this->openChat($chatId, app(WatchWhatsappChatPresence::class));
    }

    /**
     * The chat changed in the side panel (ChatDetails): a group left or
     * renamed, a contact blocked.
     */
    #[On('whatsapp-chat-changed')]
    public function chatChanged(): void
    {
        unset($this->chat, $this->messages);
        $this->refreshChatList();
    }

    public function loadOlder(): void
    {
        $this->messageLimit += self::MESSAGES_PER_PAGE;
        unset($this->messages);
    }

    public function send(SendWhatsappChatMessage $sendMessage): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();

        try {
            $delivery = $sendMessage($this->user(), $this->chat, $this->text, $this->attachment, $this->replyTo);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(array_filter([
                'text' => $exception->errors()['text'] ?? $exception->errors()['reply_to'] ?? [],
                'attachment' => $exception->errors()['file'] ?? [],
            ]));
        }

        if ($this->draftMessageId !== null) {
            WhatsappDraftMessage::query()
                ->where('friendly_id', $this->draftMessageId)
                ->whereHas('conversation', fn (Builder $query) => $query->where('user_id', $this->user()->id)->where('whatsapp_target_id', $this->chat->id))
                ->update(['whatsapp_delivery_id' => $delivery->id]);
        }

        $this->reset('text', 'attachment', 'replyToId', 'draftMessageId');
        unset($this->outgoing);
        $this->dispatch('whatsapp-chat-opened');
    }

    /**
     * Sends the voice note just recorded in the composer, keeping what was
     * typed for its own message.
     */
    public function sendVoice(SendWhatsappChatMessage $sendMessage): void
    {
        if ($this->chat === null || $this->voiceNote === null) {
            return;
        }

        $this->resetErrorBag();

        try {
            $sendMessage($this->user(), $this->chat, '', $this->voiceNote, $this->replyTo);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['attachment' => $exception->errors()['file'] ?? $exception->errors()['text'] ?? []]);
        } finally {
            $this->reset('voiceNote', 'replyToId');
        }

        unset($this->outgoing);
        $this->dispatch('whatsapp-chat-opened');
    }

    public function startChat(StartWhatsappChat $startChat): void
    {
        if ($this->account === null) {
            return;
        }

        $this->resetErrorBag();
        $target = $startChat($this->user(), $this->account, $this->number);

        $this->reset('showNewChat', 'number', 'search');
        $this->refreshChatList();
        $this->openChat($target->friendly_id, app(WatchWhatsappChatPresence::class));
    }

    /**
     * Opens the chat with a number of a contact card shared in a chat.
     */
    public function startChatWith(string $number, StartWhatsappChat $startChat): void
    {
        if ($this->account === null) {
            return;
        }

        try {
            $target = $startChat($this->user(), $this->account, $number);
        } catch (ValidationException) {
            $this->error = __('This number is not on WhatsApp.');

            return;
        }

        $this->refreshChatList();
        $this->openChat($target->friendly_id, app(WatchWhatsappChatPresence::class));
    }

    public function removeAttachment(): void
    {
        $this->reset('attachment');
        $this->resetErrorBag('attachment');
    }

    /**
     * Called by the open chat's avatar when its picture is due
     * (RefreshWhatsappChatPicture), after the chat shows, so opening it
     * never waits on WhatsApp.
     */
    public function refreshPicture(string $chatId, RefreshWhatsappChatPicture $refreshPicture): void
    {
        try {
            $refreshPicture($this->user(), $this->findChat($chatId));
        } catch (EvolutionApiException $exception) {
            report($exception);

            return;
        }

        unset($this->chat);
        $this->refreshChatList();
    }

    public function syncChat(SyncWhatsappChat $syncChat): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->clearMessages();

        try {
            $recorded = $syncChat($this->user(), $this->chat);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');

            return;
        }

        $this->notice = $recorded === null
            ? null
            : trans_choice('{0} Chat in sync with WhatsApp.|{1} Chat in sync with WhatsApp: 1 message brought in.|[2,*] Chat in sync with WhatsApp: :count messages brought in.', $recorded, ['count' => $recorded]);
        $this->error = $recorded === null ? __('This number is not connected') : null;
        unset($this->chat, $this->messages);
        $this->refreshChatList();
    }

    public function loadMedia(string $messageId, LoadWhatsappChatMedia $loadMedia): void
    {
        $message = $this->chat?->chatMessages()->where('friendly_id', $messageId)->firstOrFail() ?? abort(404);

        $this->clearMessages();

        try {
            if (! $loadMedia($this->user(), $message)) {
                $this->error = __('Too big. See it on the phone.');
            }
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not hand over the file. Try again in a moment.');
        }

        unset($this->messages);
    }

    public function togglePin(string $chatId, PinWhatsappChat $pinChat): void
    {
        $chat = $this->findChat($chatId);

        $pinChat($this->user(), $chat, ! $chat->isPinned());
        unset($this->chat);
        $this->refreshChatList();
    }

    public function muteChat(string $chatId, ?string $duration, MuteWhatsappChat $muteChat): void
    {
        $muteChat($this->user(), $this->findChat($chatId), $duration);
        unset($this->chat);
        $this->refreshChatList();
    }

    public function archiveChat(string $chatId, bool $archived, ArchiveWhatsappChat $archiveChat): void
    {
        $this->clearMessages();

        try {
            $archiveChat($this->user(), $this->findChat($chatId), $archived);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp did not take it. Try again in a moment.');
        }

        if ($archived && $this->chatId === $chatId) {
            $this->closeChat();
        }

        unset($this->chat);
        $this->refreshChatList();
    }

    public function markChatUnread(string $chatId, bool $unread, MarkWhatsappChatUnread $markUnread): void
    {
        $this->clearMessages();

        try {
            $markUnread($this->user(), $this->findChat($chatId), $unread);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp did not take it. Try again in a moment.');
        }

        if ($unread && $this->chatId === $chatId) {
            $this->closeChat();
        }

        unset($this->chat);
        $this->refreshChatList();
    }

    /**
     * A tool of the chat asked for from its info panel (ChatInfo): the panel
     * gives way to its dialog.
     */
    #[On('whatsapp-chat-tool')]
    public function chatTool(string $tool): void
    {
        $open = match ($tool) {
            'nickname' => $this->editNickname(...),
            'tags' => $this->editTags(...),
            'priority' => $this->editPriority(...),
            default => null,
        };

        if ($open !== null) {
            $this->showInfo = false;
            $open();
        }
    }

    public function editNickname(): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $this->nickname = (string) $this->chat->nickname;
        $this->showNickname = true;
    }

    public function saveNickname(SetWhatsappChatNickname $setNickname): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $setNickname($this->user(), $this->chat, $this->nickname);

        $this->reset('showNickname', 'nickname');
        unset($this->chat, $this->senderNicknames);
        $this->refreshChatList();
    }

    /**
     * The nicknames the user gave to the senders of the shown group
     * messages, by JID, to show in place of the names they sent.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function senderNicknames(): array
    {
        if ($this->chat === null || ! $this->chat->isGroup()) {
            return [];
        }

        return WhatsappTarget::query()
            ->where('whatsapp_account_id', $this->chat->whatsapp_account_id)
            ->whereIn('jid', $this->shownSenderJids())
            ->whereNotNull('nickname')
            ->pluck('nickname', 'jid')
            ->all();
    }

    /**
     * The color of each priority, as the user set them.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function priorityPalette(): array
    {
        return $this->user()->whatsappPriorityColors();
    }

    /**
     * The user's chat tags.
     *
     * @return Collection<int, WhatsappTag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return $this->user()->whatsappTags()->get();
    }

    public function filterByTag(string $tagId): void
    {
        $this->tagFilter = $this->tagFilter === $tagId ? '' : $tagId;
        $this->refreshChatList();
    }

    public function editTags(): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $this->chatTags = $this->chat->tags->pluck('friendly_id')->all();
        $this->reset('editingTagId', 'tagName', 'tagColor');
        $this->showTags = true;
    }

    public function saveChatTags(SetWhatsappChatTags $setTags): void
    {
        if ($this->chat === null) {
            return;
        }

        $setTags($this->user(), $this->chat, array_values($this->chatTags));
        $this->reset('showTags', 'chatTags');
        unset($this->chat);
        $this->refreshChatList();
    }

    public function editTag(string $tagId): void
    {
        $tag = $this->user()->whatsappTags()->where('friendly_id', $tagId)->firstOrFail();

        $this->resetErrorBag();
        $this->editingTagId = $tag->friendly_id;
        $this->tagName = $tag->name;
        $this->tagColor = $tag->color;
    }

    /**
     * Creates a tag (picked for the open chat when its tags are being set)
     * or saves the one being edited.
     */
    public function saveTag(SaveWhatsappTag $saveTag): void
    {
        $this->resetErrorBag();
        $editing = $this->editingTagId !== null ? $this->user()->whatsappTags()->where('friendly_id', $this->editingTagId)->firstOrFail() : null;

        try {
            $tag = $saveTag($this->user(), $editing, $this->tagName, $this->tagColor);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['tag'.ucfirst($field) => $messages])->all());
        }

        if ($editing === null && $this->showTags) {
            $this->chatTags[] = $tag->friendly_id;
        }

        $this->reset('editingTagId', 'tagName', 'tagColor');
        unset($this->tags, $this->chat);
        $this->refreshChatList();
    }

    public function deleteTag(string $tagId, DeleteWhatsappTag $deleteTag): void
    {
        $deleteTag($this->user(), $this->user()->whatsappTags()->where('friendly_id', $tagId)->firstOrFail());

        if ($this->tagFilter === $tagId) {
            $this->tagFilter = '';
        }

        $this->chatTags = array_values(array_diff($this->chatTags, [$tagId]));
        $this->reset('editingTagId', 'tagName', 'tagColor');
        unset($this->tags, $this->chat);
        $this->refreshChatList();
    }

    public function openSettings(): void
    {
        $this->resetErrorBag();
        $this->accountPrivacy = [
            'read_receipts' => (bool) $this->account?->sends_read_receipts,
            'typing' => (bool) $this->account?->sends_typing,
            'notifications' => (bool) ($this->account?->notifies_messages ?? true),
            'phone_reads' => (bool) ($this->account?->syncs_phone_reads ?? true),
        ];
        $choice = fn (?bool $value): string => match ($value) {
            null => self::FOLLOW_ACCOUNT,
            true => 'on',
            false => 'off',
        };
        $this->chatPrivacy = [
            'read_receipts' => $choice($this->chat?->sends_read_receipts),
            'typing' => $choice($this->chat?->sends_typing),
            'notifications' => $choice($this->chat?->notifies_messages),
        ];
        $this->showSettings = true;
    }

    public function savePrivacy(SetWhatsappChatPrivacy $setPrivacy, SetWhatsappChatNotifications $setNotifications): void
    {
        if ($this->account === null) {
            return;
        }

        $setPrivacy->forAccount($this->user(), $this->account, (bool) $this->accountPrivacy['read_receipts'], (bool) $this->accountPrivacy['typing']);
        $setNotifications->forAccount($this->user(), $this->account, (bool) $this->accountPrivacy['notifications']);
        $setNotifications->syncPhoneReads($this->user(), $this->account, (bool) ($this->accountPrivacy['phone_reads'] ?? true));

        if ($this->chat !== null) {
            $value = fn (string $choice): ?bool => match ($choice) {
                'on' => true,
                'off' => false,
                default => null,
            };
            $setPrivacy->forChat($this->user(), $this->chat, $value($this->chatPrivacy['read_receipts']), $value($this->chatPrivacy['typing']));
            $setNotifications->forChat($this->user(), $this->chat, $value($this->chatPrivacy['notifications']));
        }

        unset($this->account, $this->accounts, $this->chat);
        $this->refreshChatList();
        $this->notice = __('Privacy saved.');
    }

    /**
     * Called by the screen while the open chat is in view (the tab visible),
     * so WhatsApp hears it was read only when the user could see it.
     */
    public function sendReadReceipts(SendWhatsappReadReceipts $sendReceipts): void
    {
        if ($this->chat !== null) {
            $sendReceipts($this->user(), $this->chat);
        }

        $this->skipRender();
    }

    /**
     * Called by the screen every few seconds while the user types.
     */
    public function typing(ShowWhatsappTyping $showTyping): void
    {
        if ($this->chat !== null) {
            $showTyping($this->user(), $this->chat);
        }

        $this->skipRender();
    }

    public function editPriority(): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $this->priority = (string) ($this->chat->priority ?? '');
        $this->showPriority = true;
    }

    public function savePriority(SetWhatsappChatPriority $setPriority): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $setPriority($this->user(), $this->chat, $this->priority === '' ? null : (int) $this->priority);

        $this->reset('showPriority', 'priority');
        unset($this->chat);
        $this->refreshChatList();
    }

    public function startReminder(?string $messageId = null): void
    {
        if ($this->chat === null) {
            return;
        }

        $message = $messageId !== null ? $this->chat->chatMessages()->where('friendly_id', $messageId)->first() : null;

        $this->resetErrorBag();
        $this->reminderMessageId = $message?->friendly_id;
        $this->reminderTitle = $message !== null && ! $message->from_me
            ? __('Answer :name', ['name' => $this->chat->displayName()])
            : __('Talk to :name', ['name' => $this->chat->displayName()]);
        $this->reminderDueAt = '';
        $this->showReminder = true;
    }

    public function createReminder(CreateWhatsappChatReminder $createReminder): void
    {
        if ($this->chat === null) {
            return;
        }

        $this->resetErrorBag();
        $message = $this->reminderMessageId !== null ? $this->chat->chatMessages()->where('friendly_id', $this->reminderMessageId)->first() : null;

        $reminder = $createReminder($this->user(), $this->chat, $message !== null ? [$message] : [], ['title' => $this->reminderTitle, 'due_at' => $this->reminderDueAt]);

        $this->reset('showReminder', 'reminderMessageId', 'reminderTitle', 'reminderDueAt');
        $this->notice = __('Reminder “:title” created.', ['title' => $reminder->title]);
        unset($this->chatReminders);
    }

    /**
     * Opens the schedule with the text being typed: once, tomorrow at 09:00.
     */
    public function startSchedule(): void
    {
        $this->resetErrorBag();
        $this->reset('scheduleWithAi', 'schedulePrompt');
        $this->scheduleRecurrence->load($this->user()->timezone);
        $this->scheduleRecurrence->frequency = RecurrenceFrequency::ONCE;
        $this->scheduleRecurrence->starts_at = now()->setTimezone($this->scheduleRecurrence->timezone)->addDay()->setTime(9, 0)->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
        $this->showSchedule = true;
    }

    public function schedule(ScheduleWhatsappMessage $scheduleMessage): void
    {
        if ($this->chat === null || $this->account === null) {
            return;
        }

        $this->resetErrorBag();

        if ($this->scheduleWithAi) {
            $this->validate(['schedulePrompt' => ['required', 'string']], attributes: ['schedulePrompt' => __('prompt')]);
        }

        try {
            $scheduleMessage($this->user(), [
                'account' => $this->account->friendly_id,
                'targets' => [$this->chat->friendly_id],
                'body' => $this->scheduleWithAi ? '' : $this->text,
                'ai_prompt' => $this->scheduleWithAi ? $this->schedulePrompt : null,
                'file' => $this->scheduleWithAi ? null : $this->scheduleFile,
                'recurrence' => $this->scheduleRecurrence->data(),
            ]);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = match (true) {
                    str_starts_with($field, 'recurrence.') => 'scheduleRecurrence.'.substr($field, strlen('recurrence.')),
                    $field === 'body' => 'text',
                    $field === 'ai_prompt' => 'schedulePrompt',
                    $field === 'file' => 'scheduleFile',
                    default => 'schedule',
                };
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }

        // A message the AI writes leaves the text being typed where it is.
        $this->reset(['showSchedule', 'scheduleWithAi', 'schedulePrompt', 'scheduleFile', ...($this->scheduleWithAi ? [] : ['text'])]);

        $this->notice = __('Message scheduled.');
        unset($this->scheduled);
    }

    public function cancelScheduled(string $messageId, CancelWhatsappMessage $cancelMessage): void
    {
        $message = WhatsappMessage::query()->where('friendly_id', $messageId)->firstOrFail();

        $this->clearMessages();

        try {
            $cancelMessage($this->user(), $message);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->notice = __('Scheduled message cancelled.');
        unset($this->scheduled);
    }

    /**
     * Picks a message for the AI to answer, or unpicks it (up to
     * DraftWhatsappReply::MAX_MESSAGES).
     */
    public function toggleSelected(string $messageId): void
    {
        if (in_array($messageId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$messageId]));

            return;
        }

        if (count($this->selected) < DraftWhatsappReply::MAX_MESSAGES) {
            $this->selected[] = $messageId;
        }
    }

    public function clearSelected(): void
    {
        $this->reset('selected');
    }

    /**
     * The owner's reaction to a message of the open chat; the same emoji
     * again removes it (SendWhatsappReaction).
     */
    public function react(string $messageId, string $emoji, SendWhatsappReaction $sendReaction): void
    {
        $message = $this->chat?->chatMessages()->where('friendly_id', $messageId)->firstOrFail() ?? abort(404);

        $this->clearMessages();

        try {
            $sendReaction($this->user(), $message, $emoji);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp did not take the reaction. Try again in a moment.');
        }

        unset($this->messages);
    }

    /**
     * Opens the forwarding of one message, or of the selected ones.
     */
    public function startForward(?string $messageId = null): void
    {
        $this->forwardIds = $messageId !== null ? [$messageId] : $this->selected;

        if ($this->forwardIds === []) {
            return;
        }

        $this->resetErrorBag();
        $this->reset('forwardTo', 'forwardSearch');
        $this->showForward = true;
    }

    /**
     * The chats of the number to forward to, by name or number, the latest
     * first; the chosen ones always show.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function forwardChats(): Collection
    {
        if ($this->account === null) {
            return new Collection;
        }

        $search = mb_trim($this->forwardSearch);

        return $this->account->targets()
            ->where(fn (Builder $query) => $query
                ->whereIn('friendly_id', $this->forwardTo)
                ->orWhere(fn (Builder $query) => $query
                    ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('nickname', "%{$search}%")
                        ->orWhereLike('jid', '%'.ltrim($search, '+').'%')))))
            ->orderByRaw('last_message_at is null')
            ->orderByDesc('last_message_at')
            ->limit(30)
            ->get();
    }

    public function forward(ForwardWhatsappChatMessages $forward): void
    {
        if ($this->chat === null || $this->account === null) {
            return;
        }

        $this->resetErrorBag();
        $messages = $this->chat->chatMessages()->whereIn('friendly_id', $this->forwardIds)->get()->all();
        $chats = $this->account->targets()->whereIn('friendly_id', $this->forwardTo)->get()
            ->sortBy(fn (WhatsappTarget $target): int|false => array_search($target->friendly_id, $this->forwardTo, true))->values()->all();

        try {
            $forward($this->user(), $messages, $chats);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            throw ValidationException::withMessages(array_filter([
                'forwardTo' => [...($errors['chats'] ?? []), ...($errors['messages'] ?? [])],
            ]));
        }

        $this->notice = trans_choice('{1} Forwarding to 1 chat.|[2,*] Forwarding to :count chats.', count($chats), ['count' => count($chats)]);
        $this->reset('showForward', 'forwardIds', 'forwardTo', 'forwardSearch', 'selected');
        unset($this->outgoing);
    }

    /**
     * A message the AI wrote (DraftAssistant) goes into the composer.
     */
    #[On('whatsapp-use-draft')]
    public function useDraft(string $text, ?string $draftMessageId = null): void
    {
        $this->text = $text;
        $this->draftMessageId = $draftMessageId;
        $this->reset('selected');
        $this->dispatch('whatsapp-chat-drafted');
    }

    /**
     * A change the AI wrote to a message already sent (DraftAssistant opened
     * from it): the edit dialog opens with it, to be confirmed.
     */
    #[On('whatsapp-edit-sent')]
    public function editSent(string $messageId, string $text): void
    {
        $this->startEdit($messageId);
        $this->editText = $text;
    }

    /**
     * The conversations with the AI the shown messages were written in, by
     * their WhatsApp id.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function draftedMessages(): array
    {
        $sent = $this->messages->where('from_me', true)->pluck('external_id')->filter()->values()->all();

        if ($this->chat === null || $sent === []) {
            return [];
        }

        return WhatsappDraftMessage::query()
            ->join('whatsapp_deliveries', 'whatsapp_deliveries.id', '=', 'whatsapp_draft_messages.whatsapp_delivery_id')
            ->join('whatsapp_draft_conversations', 'whatsapp_draft_conversations.id', '=', 'whatsapp_draft_messages.whatsapp_draft_conversation_id')
            ->where('whatsapp_draft_conversations.user_id', $this->user()->id)
            ->where('whatsapp_deliveries.whatsapp_target_id', $this->chat->id)
            ->whereIn('whatsapp_deliveries.external_message_id', $sent)
            ->pluck('whatsapp_draft_conversations.friendly_id', 'whatsapp_deliveries.external_message_id')
            ->all();
    }

    /**
     * A chat of the user got a message (`WhatsappChatUpdated`).
     *
     * @param  array{account_id?: string, chat_id?: string}  $payload
     */
    public function chatUpdated(array $payload = []): void
    {
        unset($this->messages, $this->outgoing, $this->scheduled);
        $this->refreshChatList();

        if ($this->chat !== null && ($payload['chat_id'] ?? null) === $this->chat->friendly_id) {
            $this->markRead($this->chat);
            $this->dispatch('whatsapp-chat-received');
        }
    }

    /**
     * Asks the AI to prioritize the unread chats picked in the list
     * (RequestWhatsappChatTriage); the list shows them waiting until it
     * answers (chatsTriaged()).
     *
     * @param  array<int, mixed>  $chatIds
     */
    public function triageChats(array $chatIds, RequestWhatsappChatTriage $requestTriage): void
    {
        if ($this->account === null) {
            return;
        }

        $this->clearMessages();

        try {
            $count = $requestTriage($this->user(), $this->account, $chatIds);
        } catch (AiProviderNotConfigured) {
            $this->error = __('No AI is set up for WhatsApp replies. Ask the administrator to set one.');

            return;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->notice = trans_choice('{1} The AI is prioritizing 1 chat. The list sorts by it under “AI”.|[2,*] The AI is prioritizing :count chats. The list sorts by it under “AI”.', $count, ['count' => $count]);
        $this->refreshChatList();
    }

    /**
     * Does one thing to the chats picked in the list
     * (ApplyWhatsappChatsBulkAction): read, unread, archive, mute, clear the
     * user's or the AI's priority…
     *
     * @param  array<int, mixed>  $chatIds
     */
    public function bulkAction(string $action, array $chatIds, ApplyWhatsappChatsBulkAction $applyBulkAction): void
    {
        if ($this->account === null) {
            return;
        }

        $this->clearMessages();

        try {
            ['done' => $done, 'failed' => $failed] = $applyBulkAction($this->user(), $this->account, $action, $chatIds);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        if ($failed > 0) {
            $this->error = trans_choice('{1} WhatsApp did not take 1 chat. Try it again in a moment.|[2,*] WhatsApp did not take :count chats. Try them again in a moment.', $failed, ['count' => $failed]);
        } else {
            $this->notice = trans_choice('{0} Nothing to change in the chats picked.|{1} Done in 1 chat.|[2,*] Done in :count chats.', $done, ['count' => $done]);
        }

        if (in_array($action, ['unread', 'archive'], true) && in_array($this->chatId, $chatIds, true)) {
            $this->closeChat();
        }

        unset($this->chat);
        $this->refreshChatList();
    }

    /**
     * The AI answered a triage, or gave up (`WhatsappChatsTriaged`).
     *
     * @param  array{account_id?: string, failed?: bool}  $payload
     */
    public function chatsTriaged(array $payload = []): void
    {
        if ($payload['failed'] ?? false) {
            $this->error = __('The AI could not prioritize the chats. Try again in a moment.');
        }

        $this->refreshChatList();
    }

    public function render(): View
    {
        return view('livewire.whatsapp.chat')->title(__('WhatsApp'));
    }

    /**
     * Renders the chat list after the screen, once it is final, when
     * something changed a chat of it (refreshChatList()).
     */
    public function rendered(): void
    {
        if ($this->chatListChanged) {
            $this->chatListChanged = false;
            $this->renderIsland('chats');
        }
    }

    /**
     * @return list<string>
     */
    private function shownSenderJids(): array
    {
        return $this->messages
            ->where('from_me', false)
            ->pluck('sender_jid')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A message of the open chat, with its chat.
     */
    private function chatMessage(string $messageId): WhatsappChatMessage
    {
        $chat = $this->chat ?? abort(404);

        return $chat->chatMessages()->where('friendly_id', $messageId)->firstOrFail()->setRelation('target', $chat);
    }

    private function findChat(string $chatId): WhatsappTarget
    {
        return $this->account?->targets()->where('friendly_id', $chatId)->firstOrFail()
            ?? abort(404);
    }

    /**
     * The chat list is an island the screen's renders skip: what changes a
     * chat of it asks for it to render as well.
     */
    private function refreshChatList(): void
    {
        unset($this->chats, $this->archivedCount);
        $this->chatListChanged = true;
    }

    private function clearMessages(): void
    {
        $this->notice = null;
        $this->error = null;
    }

    private function markRead(WhatsappTarget $chat): void
    {
        $this->authorize('use', $chat->account);

        if ($chat->unread_count > 0 || $chat->marked_unread) {
            $chat->forceFill(['unread_count' => 0, 'marked_unread' => false])->save();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
