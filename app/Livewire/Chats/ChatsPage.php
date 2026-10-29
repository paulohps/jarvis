<?php

namespace App\Livewire\Chats;

use App\Actions\Chats\ApplyChatConversationsBulkAction;
use App\Actions\Chats\ArchiveChatConversation;
use App\Actions\Chats\CancelChatScheduledMessage;
use App\Actions\Chats\CreateChatReminder;
use App\Actions\Chats\DeleteChatMessage;
use App\Actions\Chats\DeleteChatTag;
use App\Actions\Chats\DraftChatReply;
use App\Actions\Chats\EditChatMessage;
use App\Actions\Chats\FindsChatConversations;
use App\Actions\Chats\LinkChatContact;
use App\Actions\Chats\MarkChatConversationRead;
use App\Actions\Chats\MarkChatConversationUnread;
use App\Actions\Chats\MuteChatConversation;
use App\Actions\Chats\PinChatConversation;
use App\Actions\Chats\ReactToChatMessage;
use App\Actions\Chats\RequestChatTriage;
use App\Actions\Chats\SaveChatTag;
use App\Actions\Chats\ScheduleChatMessage;
use App\Actions\Chats\SendChatMessage;
use App\Actions\Chats\SendChatPoll;
use App\Actions\Chats\SetChatConversationNickname;
use App\Actions\Chats\SetChatConversationPriority;
use App\Actions\Chats\SetChatConversationTags;
use App\Actions\Chats\SyncChatConversation;
use App\Actions\Chats\UnlinkChatContact;
use App\Events\NotificationCreated;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\ChatScheduledMessage;
use App\Models\ChatTag;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Chats\ChatCapabilities;
use App\Services\Chats\ChatMessageData;
use App\Services\Chats\ChatProviderDriver;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use App\Services\Chats\OutgoingChatFile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The unified chat screen (Key Concept "Chat channel"): every conversation
 * of every channel the user may use, in one list with the channel's mark
 * on each, beside the open one, full window in a tab of its own (layout
 * `chat`).
 *
 * - The list filters by channel, tag and search (name or nickname), sorts
 *   by the latest message, by priority or by the AI's urgency (pinned
 *   first either way) and keeps the archived ones apart. Opening a
 *   conversation marks it read (MarkChatConversationRead).
 * - Its selection picks many conversations, to do one thing to them all
 *   (ApplyChatConversationsBulkAction) or to have the AI prioritize the
 *   unread ones (RequestChatTriage), apart from the user's priority.
 * - The open conversation shows its latest messages through its channel's
 *   driver, loads older ones on demand (from Jarvis, then from the channel
 *   when it keeps history), and offers only what the channel supports on
 *   it (ChatCapabilities): sending texts and files, threads in a side
 *   panel, reactions, editing and deleting one's own messages, the link to
 *   the channel. A read-only conversation says why.
 * - What Jarvis adds works on every conversation: pin, priority, nickname,
 *   tags, archive, mute, mark unread, the contact that links the same
 *   person's conversations across channels, reminders (with the
 *   `reminders` module), scheduled messages and a message drafted by the
 *   AI in the user's style.
 * - `ChatConversationUpdated` on the user's private channel reloads the
 *   list and the open conversation.
 *
 * @property-read Collection<int, ChatProvider> $providers
 * @property-read Collection<int, ChatConversation> $conversations
 * @property-read ChatConversation|null $conversation
 * @property-read ChatCapabilities|null $capabilities
 * @property-read BaseCollection<int, ChatMessageData> $messages
 * @property-read BaseCollection<int, ChatMessageData> $thread
 * @property-read Collection<int, ChatTag> $tags
 * @property-read Collection<int, Reminder> $conversationReminders
 * @property-read Collection<int, ChatScheduledMessage> $scheduled
 * @property-read Collection<int, ChatConversation> $linkCandidates
 */
#[Layout('layouts::chat')]
class ChatsPage extends Component
{
    use FindsChatConversations, WithFileUploads;

    public const int MESSAGES_PER_PAGE = 50;

    public const int CONVERSATIONS_SHOWN = 200;

    public const string SORT_RECENT = 'recent';

    public const string SORT_PRIORITY = 'priority';

    public const string SORT_AI = 'ai';

    /**
     * The reactions offered at once; the others are a click away.
     */
    public const array QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    #[Locked]
    public int $userId = 0;

    #[Url(as: 'conversation')]
    public ?string $conversationId = null;

    #[Url(as: 'thread', except: '')]
    public string $threadId = '';

    public string $search = '';

    #[Url(as: 'sort', except: self::SORT_RECENT)]
    public string $sort = self::SORT_RECENT;

    #[Url(as: 'provider', except: '')]
    public string $providerFilter = '';

    #[Url(as: 'tag', except: '')]
    public string $tagFilter = '';

    public bool $showArchived = false;

    public int $messageLimit = self::MESSAGES_PER_PAGE;

    public string $text = '';

    /**
     * @var list<TemporaryUploadedFile>
     */
    public array $attachments = [];

    public string $threadText = '';

    public ?string $notice = null;

    public ?string $error = null;

    public bool $showNickname = false;

    public string $nickname = '';

    public bool $showPriority = false;

    public string $priority = '';

    public bool $showTags = false;

    /**
     * @var list<string>
     */
    public array $conversationTags = [];

    #[Locked]
    public ?string $editingTagId = null;

    public string $tagName = '';

    public string $tagColor = 'blue';

    public bool $showContact = false;

    public string $contactSearch = '';

    public string $contactName = '';

    public bool $showReminder = false;

    public ?string $reminderMessageId = null;

    public string $reminderTitle = '';

    public string $reminderDueAt = '';

    public bool $showSchedule = false;

    public string $scheduleBody = '';

    public RecurrencePicker $scheduleRecurrence;

    /** Whether the AI writes the scheduled message when it goes out, from `schedulePrompt`. */
    public bool $scheduleWithAi = false;

    public string $schedulePrompt = '';

    public bool $showPoll = false;

    public RecurrencePicker $pollRecurrence;

    public bool $showDraft = false;

    public string $draftInstruction = '';

    public string $draft = '';

    /**
     * The messages picked for the AI to answer together.
     *
     * @var list<string>
     */
    public array $selected = [];

    public bool $showEdit = false;

    #[Locked]
    public ?string $editingMessageId = null;

    public string $editText = '';

    public bool $showEmoji = false;

    #[Locked]
    public ?string $emojiMessageId = null;

    /**
     * The list changed in this request (refreshList()).
     */
    private bool $listChanged = false;

    public function mount(): void
    {
        $this->userId = $this->user()->id;
        $this->resetRecurrence($this->scheduleRecurrence);
        $this->resetRecurrence($this->pollRecurrence);

        if ($this->conversation !== null) {
            $this->markRead($this->conversation);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-private:'.NotificationCreated::channelFor($this->userId);

        return [
            "{$channel},.chat.conversation.updated" => 'conversationUpdated',
            "{$channel},.whatsapp.chat.updated" => 'whatsappChatUpdated',
            "{$channel},.chat.account.synced" => 'accountSynced',
            "{$channel},.chat.conversations.triaged" => 'conversationsTriaged',
            "{$channel},.whatsapp.chats.triaged" => 'accountSynced',
        ];
    }

    /**
     * The channels the user may use, in menu order.
     *
     * @return Collection<int, ChatProvider>
     */
    #[Computed]
    public function providers(): Collection
    {
        return app(ChatProviderRegistry::class)->providersFor($this->user());
    }

    /**
     * @return Collection<int, ChatConversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $search = mb_trim($this->search);
        $providerIds = $this->providers->modelKeys();

        if ($this->providerFilter !== '') {
            $providerIds = $this->providers->where('slug', $this->providerFilter)->modelKeys();
        }

        return ChatConversation::query()
            ->ofUser($this->user())
            ->whereIn('chat_provider_id', $providerIds)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('nickname', "%{$search}%")))
            ->when($this->tagFilter !== '', fn (Builder $query) => $query->whereHas('tags', fn (Builder $query) => $query->where('friendly_id', $this->tagFilter)))
            ->when($search === '', fn (Builder $query) => $this->showArchived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at'))
            ->with(['provider', 'tags', 'contact'])
            ->when(
                $this->sort,
                fn (Builder $query, string $sort) => match ($sort) {
                    self::SORT_PRIORITY => $query->priorityFirst(),
                    self::SORT_AI => $query->aiUrgencyFirst(),
                    default => $query->recentFirst(),
                },
            )
            ->limit(self::CONVERSATIONS_SHOWN)
            ->get();
    }

    #[Computed]
    public function archivedCount(): int
    {
        return ChatConversation::query()->ofUser($this->user())->whereIn('chat_provider_id', $this->providers->modelKeys())->whereNotNull('archived_at')->count();
    }

    #[Computed]
    public function conversation(): ?ChatConversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        $conversation = ChatConversation::query()
            ->ofUser($this->user())
            ->byFriendlyId($this->conversationId)
            ->with(['provider', 'conversable', 'contact', 'tags'])
            ->first();

        if ($conversation === null || ! $this->providers->contains('id', $conversation->chat_provider_id)) {
            return null;
        }

        return $conversation;
    }

    #[Computed]
    public function capabilities(): ?ChatCapabilities
    {
        return $this->conversation === null ? null : $this->driver()->capabilities($this->conversation);
    }

    /**
     * The messages of the open conversation, oldest first.
     *
     * @return BaseCollection<int, ChatMessageData>
     */
    #[Computed]
    public function messages(): BaseCollection
    {
        if ($this->conversation === null) {
            return new BaseCollection;
        }

        return $this->driver()->messages($this->conversation, null, $this->messageLimit);
    }

    public function hasOlderMessages(): bool
    {
        $oldest = $this->messages->first();

        return $oldest !== null && $this->conversation !== null && $this->driver()->hasOlderMessages($this->conversation, $oldest->id);
    }

    /**
     * The open thread: its parent and the replies, oldest first.
     *
     * @return BaseCollection<int, ChatMessageData>
     */
    #[Computed]
    public function thread(): BaseCollection
    {
        if ($this->conversation === null || $this->threadId === '' || $this->capabilities?->threads !== true) {
            return new BaseCollection;
        }

        try {
            return $this->driver()->thread($this->conversation, $this->threadId);
        } catch (ChatProviderException $exception) {
            $this->error = $exception->getMessage();

            return new BaseCollection;
        }
    }

    /**
     * The user's tags, by name.
     *
     * @return Collection<int, ChatTag>
     */
    #[Computed]
    public function tags(): Collection
    {
        return $this->user()->chatTags()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Reminder>
     */
    #[Computed]
    public function conversationReminders(): Collection
    {
        return $this->conversation?->reminders()->with('occurrences')->latest('reminders.id')->limit(5)->get() ?? new Collection;
    }

    /**
     * @return Collection<int, ChatScheduledMessage>
     */
    #[Computed]
    public function scheduled(): Collection
    {
        return $this->conversation?->scheduledMessages()->pending()->soonestFirst()->with('recurrence.frequency')->get() ?? new Collection;
    }

    /**
     * The other conversations the open one may be linked to, by the search.
     *
     * @return Collection<int, ChatConversation>
     */
    #[Computed]
    public function linkCandidates(): Collection
    {
        if ($this->conversation === null) {
            return new Collection;
        }

        $search = mb_trim($this->contactSearch);

        return ChatConversation::query()
            ->ofUser($this->user())
            ->whereKeyNot($this->conversation->id)
            ->whereIn('chat_provider_id', $this->providers->modelKeys())
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('nickname', "%{$search}%")))
            ->with(['provider', 'contact'])
            ->recentFirst()
            ->limit(8)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function priorityPalette(): array
    {
        return $this->user()->whatsappPriorityColors();
    }

    public function openConversation(string $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->threadId = '';
        $this->messageLimit = self::MESSAGES_PER_PAGE;
        $this->reset('text', 'attachments', 'threadText', 'notice', 'error', 'selected', 'draft');
        $this->resetRecurrence($this->scheduleRecurrence);
        $this->resetRecurrence($this->pollRecurrence);
        unset($this->conversation, $this->capabilities, $this->messages, $this->thread);

        if ($this->conversation !== null && $this->conversation->isUnread()) {
            $this->markRead($this->conversation);
            $this->refreshList();
        }

        $this->dispatch('chat-opened');
    }

    public function closeConversation(): void
    {
        $this->conversationId = null;
        $this->threadId = '';
        $this->reset('text', 'attachments', 'threadText', 'notice', 'error', 'selected');
        unset($this->conversation, $this->capabilities, $this->messages, $this->thread);
    }

    public function filterByTag(string $tagId): void
    {
        $this->tagFilter = $this->tagFilter === $tagId ? '' : $tagId;
        $this->refreshList();
    }

    public function filterByProvider(string $slug): void
    {
        $this->providerFilter = $this->providerFilter === $slug ? '' : $slug;
        $this->refreshList();
    }

    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    public function updatedSort(): void
    {
        $this->refreshList();
    }

    public function updatedShowArchived(): void
    {
        $this->refreshList();
    }

    /**
     * More messages: the ones Jarvis has before the shown ones, or, once
     * those run out, the channel's own history.
     */
    public function loadOlder(SyncChatConversation $sync): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        $oldest = $this->messages->first();
        $known = $this->driver()->messages($conversation, null, $this->messageLimit + self::MESSAGES_PER_PAGE);

        if ($oldest !== null && $known->count() <= $this->messages->count() && $this->capabilities?->history === true) {
            $this->guard(fn () => $sync($this->user(), $conversation, $oldest->id));
        }

        $this->messageLimit += self::MESSAGES_PER_PAGE;
        unset($this->messages);
    }

    public function send(SendChatMessage $sendMessage): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        $this->resetValidation();
        $files = array_map(fn (TemporaryUploadedFile $file): OutgoingChatFile => new OutgoingChatFile(
            $file->getRealPath(),
            $file->getClientOriginalName(),
            $file->getMimeType(),
        ), $this->attachments);

        $sent = $this->guard(fn () => $sendMessage($this->user(), $conversation, $this->text, $files));

        if ($sent !== null) {
            $this->reset('text', 'attachments', 'draft');
            unset($this->messages, $this->conversation);
            $this->refreshList();
            $this->dispatch('chat-sent');
        }
    }

    public function sendInThread(SendChatMessage $sendMessage): void
    {
        $conversation = $this->conversation;

        if ($conversation === null || $this->threadId === '') {
            return;
        }

        $this->resetValidation();
        $sent = $this->guard(fn () => $sendMessage($this->user(), $conversation, $this->threadText, [], $this->threadId));

        if ($sent !== null) {
            $this->reset('threadText');
            unset($this->thread, $this->messages);
        }
    }

    public function removeAttachment(int $index): void
    {
        $this->attachments = array_values(array_filter($this->attachments, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function openThread(string $messageId): void
    {
        $this->threadId = $messageId;
        $this->reset('threadText');
        unset($this->thread);
    }

    public function closeThread(): void
    {
        $this->threadId = '';
        unset($this->thread);
    }

    public function react(string $messageId, string $emoji, bool $on, ReactToChatMessage $reactTo): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        $this->showEmoji = false;
        $this->guard(fn () => $reactTo($this->user(), $conversation, $messageId, $emoji, $on));
        unset($this->messages, $this->thread);
    }

    public function pickEmoji(string $messageId): void
    {
        $this->emojiMessageId = $messageId;
        $this->showEmoji = true;
    }

    public function startEdit(string $messageId): void
    {
        $message = $this->messageById($messageId);

        if ($message === null) {
            return;
        }

        $this->editingMessageId = $messageId;
        $this->editText = (string) $message->text;
        $this->showEdit = true;
    }

    public function saveEdit(EditChatMessage $editMessage): void
    {
        $conversation = $this->conversation;

        if ($conversation === null || $this->editingMessageId === null) {
            return;
        }

        $this->resetValidation();
        $edited = $this->guard(fn () => $editMessage($this->user(), $conversation, (string) $this->editingMessageId, $this->editText));

        if ($edited !== null) {
            $this->showEdit = false;
            $this->reset('editingMessageId', 'editText');
            unset($this->messages, $this->thread);
            $this->refreshList();
        }
    }

    public function deleteMessage(string $messageId, DeleteChatMessage $deleteMessage): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        $this->guard(fn () => $deleteMessage($this->user(), $conversation, $messageId));
        unset($this->messages, $this->thread);
        $this->refreshList();
    }

    public function togglePin(string $conversationId, PinChatConversation $pin): void
    {
        $conversation = $this->conversationOf($this->user(), $conversationId);

        $this->guard(fn () => $pin($this->user(), $conversation, ! $conversation->isPinned()));
        $this->refreshConversation();
    }

    public function markUnread(string $conversationId, bool $unread, MarkChatConversationUnread $markUnread, MarkChatConversationRead $markRead): void
    {
        $conversation = $this->conversationOf($this->user(), $conversationId);

        $done = $this->guard(fn () => $unread ? $markUnread($this->user(), $conversation) : $markRead($this->user(), $conversation));

        if ($done !== null && $unread && $this->conversation?->is($conversation)) {
            $this->closeConversation();
        }

        $this->refreshConversation();
    }

    public function archive(string $conversationId, bool $archived, ArchiveChatConversation $archiveConversation): void
    {
        $conversation = $this->conversationOf($this->user(), $conversationId);

        if ($this->guard(fn () => $archiveConversation($this->user(), $conversation, $archived)) === null) {
            return;
        }

        if ($this->conversation?->is($conversation)) {
            $this->closeConversation();
        }

        $this->notice = $archived ? __('Conversation archived.') : __('Conversation unarchived.');
        $this->refreshConversation();
    }

    public function mute(string $conversationId, ?string $duration, MuteChatConversation $muteConversation): void
    {
        $this->guard(fn () => $muteConversation($this->user(), $this->conversationOf($this->user(), $conversationId), $duration));
        $this->refreshConversation();
    }

    public function editPriority(): void
    {
        $this->priority = (string) ($this->conversation->priority ?? '');
        $this->showPriority = true;
    }

    public function savePriority(SetChatConversationPriority $setPriority): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();

        if ($this->guard(fn () => $setPriority($this->user(), $this->conversation, $this->priority === '' ? null : (int) $this->priority)) === null) {
            return;
        }

        $this->showPriority = false;
        $this->refreshConversation();
    }

    public function editNickname(): void
    {
        $this->nickname = (string) ($this->conversation->nickname ?? '');
        $this->showNickname = true;
    }

    public function saveNickname(SetChatConversationNickname $setNickname): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();

        if ($this->guard(fn () => $setNickname($this->user(), $this->conversation, $this->nickname)) === null) {
            return;
        }

        $this->showNickname = false;
        $this->refreshConversation();
    }

    public function editTags(): void
    {
        $this->conversationTags = $this->conversation === null ? [] : array_values($this->conversation->tags->map(fn (ChatTag $tag): string => $tag->friendly_id)->all());
        $this->reset('editingTagId', 'tagName');
        $this->tagColor = 'blue';
        $this->showTags = true;
    }

    public function saveConversationTags(SetChatConversationTags $setTags): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();
        $setTags($this->user(), $this->conversation, $this->conversationTags);
        $this->showTags = false;
        $this->refreshConversation();
    }

    public function editTag(string $tagId): void
    {
        $tag = $this->tags->firstWhere('friendly_id', $tagId);

        if ($tag === null) {
            return;
        }

        $this->editingTagId = $tagId;
        $this->tagName = $tag->name;
        $this->tagColor = $tag->color;
    }

    public function saveTag(SaveChatTag $saveTag): void
    {
        $this->resetValidation();

        $tag = $this->editingTagId === null ? null : $this->tags->firstWhere('friendly_id', $this->editingTagId);
        $saved = $saveTag($this->user(), $tag, $this->tagName, $this->tagColor);

        if ($tag === null && ! in_array($saved->friendly_id, $this->conversationTags, true)) {
            $this->conversationTags[] = $saved->friendly_id;
        }

        $this->reset('editingTagId', 'tagName');
        $this->tagColor = 'blue';
        unset($this->tags);
    }

    public function deleteTag(string $tagId, DeleteChatTag $deleteTag): void
    {
        $tag = $this->tags->firstWhere('friendly_id', $tagId);

        if ($tag === null) {
            return;
        }

        $deleteTag($this->user(), $tag);
        $this->conversationTags = array_values(array_diff($this->conversationTags, [$tagId]));

        if ($this->tagFilter === $tagId) {
            $this->tagFilter = '';
        }

        unset($this->tags);
        $this->refreshConversation();
    }

    public function openContact(): void
    {
        $this->reset('contactSearch', 'contactName');
        $this->showContact = true;
    }

    public function linkToConversation(string $otherId, LinkChatContact $link): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();
        $contact = $this->guard(fn () => $link($this->user(), $this->conversation, $this->conversationOf($this->user(), $otherId)));

        if ($contact !== null) {
            $this->showContact = false;
            $this->notice = __('Linked under :name.', ['name' => $contact->name]);
            $this->refreshConversation();
        }
    }

    public function createContact(LinkChatContact $link): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();
        $contact = $this->guard(fn () => $link($this->user(), $this->conversation, $this->contactName));

        if ($contact !== null) {
            $this->showContact = false;
            $this->notice = __('Linked under :name.', ['name' => $contact->name]);
            $this->refreshConversation();
        }
    }

    public function unlinkContact(UnlinkChatContact $unlink): void
    {
        if ($this->conversation === null) {
            return;
        }

        $unlink($this->user(), $this->conversation);
        $this->showContact = false;
        $this->refreshConversation();
    }

    public function startReminder(?string $messageId = null): void
    {
        $this->reminderMessageId = $messageId;
        $this->reminderTitle = $this->conversation === null ? '' : __('Answer :name', ['name' => $this->conversation->displayName()]);
        $this->reminderDueAt = '';
        $this->showReminder = true;
    }

    public function createReminder(CreateChatReminder $createReminder): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();
        $messageIds = $this->reminderMessageId !== null ? [$this->reminderMessageId] : $this->selected;

        $reminder = $this->guard(fn () => $createReminder($this->user(), $this->conversation, $messageIds, [
            'title' => $this->reminderTitle,
            'due_at' => $this->reminderDueAt,
        ]));

        if ($reminder !== null) {
            $this->showReminder = false;
            $this->reset('selected', 'reminderMessageId');
            $this->notice = __('Reminder “:title” created.', ['title' => $reminder->title]);
            unset($this->conversationReminders);
        }
    }

    /**
     * Opens the scheduling with the text typed, once, tomorrow at 9. The
     * screen opens it without asking (the clock of the composer); this is
     * the same from the server.
     */
    public function startSchedule(): void
    {
        $this->resetValidation();
        $this->reset('scheduleWithAi', 'schedulePrompt');
        $this->scheduleBody = $this->text;
        $this->resetRecurrence($this->scheduleRecurrence);
        $this->showSchedule = true;
    }

    /**
     * A repeating schedule starts at once: the start the single sending
     * had would skip the runs before it.
     */
    public function updatedScheduleRecurrence(mixed $value, string $key): void
    {
        if ($key === 'frequency' && $value !== RecurrenceFrequency::ONCE) {
            $this->scheduleRecurrence->starts_at = '';
        }
    }

    public function updatedPollRecurrence(mixed $value, string $key): void
    {
        if ($key === 'frequency' && $value !== RecurrenceFrequency::ONCE) {
            $this->pollRecurrence->starts_at = '';
        }
    }

    /**
     * Schedules the text, or the AI's writing from the prompt, once or
     * repeating (ScheduleChatMessage).
     */
    public function schedule(ScheduleChatMessage $scheduleMessage): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();

        $this->validate(
            [$this->scheduleWithAi ? 'schedulePrompt' : 'scheduleBody' => ['required', 'string']],
            attributes: ['schedulePrompt' => __('prompt'), 'scheduleBody' => __('message')],
        );

        try {
            $scheduled = $this->guard(fn () => $scheduleMessage($this->user(), $this->conversation, [
                'body' => $this->scheduleWithAi ? null : $this->scheduleBody,
                'ai_prompt' => $this->scheduleWithAi ? $this->schedulePrompt : null,
                'recurrence' => $this->scheduleRecurrence->data(),
            ]));
        } catch (AiProviderNotConfigured) {
            $this->addError('schedulePrompt', __('No AI provider is set up. Ask the administrator.'));

            return;
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[match (true) {
                    str_starts_with($field, 'recurrence.') => 'scheduleRecurrence.'.substr($field, strlen('recurrence.')),
                    $field === 'ai_prompt' => 'schedulePrompt',
                    $field === 'body' => $this->scheduleWithAi ? 'schedulePrompt' : 'scheduleBody',
                    default => $field,
                }] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        if ($scheduled !== null) {
            $this->reset(['showSchedule', 'scheduleBody', 'scheduleWithAi', 'schedulePrompt', ...($this->scheduleWithAi ? [] : ['text'])]);
            $this->resetRecurrence($this->scheduleRecurrence);
            $this->notice = __('Message scheduled.');
            unset($this->scheduled);
        }
    }

    /**
     * Sends the poll of its dialog to the open conversation (SendChatPoll)
     * or schedules it, once or repeating (ScheduleChatMessage). The dialog
     * keeps what is typed in the page and hands it in here.
     *
     * @param  array<mixed>  $options
     */
    public function submitPoll(mixed $question, array $options, bool $multiple, bool $schedules, SendChatPoll $sendPoll, ScheduleChatMessage $scheduleMessage): void
    {
        $conversation = $this->conversation;

        if ($conversation === null) {
            return;
        }

        $this->resetValidation();

        try {
            $done = $this->guard(fn () => $schedules
                ? $scheduleMessage($this->user(), $conversation, [
                    'poll' => ['question' => $question, 'options' => $options, 'multiple' => $multiple],
                    'recurrence' => $this->pollRecurrence->data(),
                ])
                : $sendPoll($this->user(), $conversation, $question, $options, $multiple));
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $field = str_starts_with($field, 'poll.') ? substr($field, strlen('poll.')) : $field;

                $errors[match (true) {
                    str_starts_with($field, 'recurrence.') => 'pollRecurrence.'.substr($field, strlen('recurrence.')),
                    $field === 'question' => 'pollQuestion',
                    str_starts_with($field, 'options') => 'pollOptions'.substr($field, strlen('options')),
                    default => 'poll',
                }] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        if ($done === null) {
            return;
        }

        $this->showPoll = false;

        if ($schedules) {
            $this->notice = __('Poll scheduled.');
            $this->resetRecurrence($this->pollRecurrence);
            unset($this->scheduled);

            return;
        }

        $this->notice = __('Poll sent.');
        unset($this->messages, $this->conversation);
        $this->refreshList();
        $this->dispatch('chat-sent');
    }

    public function cancelScheduled(string $scheduledId, CancelChatScheduledMessage $cancel): void
    {
        $scheduled = ChatScheduledMessage::query()->where('user_id', $this->userId)->byFriendlyId($scheduledId)->firstOrFail();

        $this->guard(fn () => $cancel($this->user(), $scheduled));
        unset($this->scheduled);
    }

    public function toggleSelected(string $messageId): void
    {
        $this->selected = in_array($messageId, $this->selected, true)
            ? array_values(array_diff($this->selected, [$messageId]))
            : [...$this->selected, $messageId];
    }

    public function clearSelected(): void
    {
        $this->selected = [];
    }

    public function startDraft(): void
    {
        $this->reset('draftInstruction', 'draft');
        $this->showDraft = true;
    }

    public function writeDraft(DraftChatReply $draftReply): void
    {
        if ($this->conversation === null) {
            return;
        }

        $this->resetValidation();

        try {
            $this->draft = $this->selected === [] && mb_trim($this->draftInstruction) !== '' && ! $this->messages->contains(fn (ChatMessageData $message): bool => ! $message->fromMe)
                ? $draftReply->fromPrompt($this->user(), $this->conversation, $this->draftInstruction)
                : $draftReply($this->user(), $this->conversation, $this->selected, $this->draftInstruction);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (AiProviderNotConfigured) {
            $this->error = __('No AI provider is set up. Ask the administrator.');
            $this->showDraft = false;
        } catch (AiAnswerCutShort $exception) {
            report($exception);
            $this->error = __('The message came out too long and was cut short. Ask for a shorter one, or try again.');
            $this->showDraft = false;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = __('The AI could not write the message. Try again.');
            $this->showDraft = false;
        }
    }

    public function useDraft(): void
    {
        $this->text = $this->draft;
        $this->showDraft = false;
        $this->reset('selected', 'draft');
    }

    public function syncConversation(SyncChatConversation $sync): void
    {
        if ($this->conversation === null) {
            return;
        }

        if ($this->guard(fn () => $sync($this->user(), $this->conversation)) !== null) {
            $this->notice = __('Conversation synced.');
        }

        $this->refreshConversation();
        unset($this->messages, $this->thread);
    }

    /**
     * A conversation of the user changed (`ChatConversationUpdated`).
     *
     * @param  array{conversation_id?: string, message_id?: string|null}  $payload
     */
    public function conversationUpdated(array $payload = []): void
    {
        $this->refreshList();

        if ($this->conversation !== null && ($payload['conversation_id'] ?? null) === $this->conversation->friendly_id) {
            unset($this->messages, $this->thread, $this->conversation, $this->capabilities);

            if ($this->conversation !== null) {
                $this->markRead($this->conversation);
            }

            $this->dispatch('chat-received');
        }
    }

    /**
     * What the WhatsApp side tells of a chat (a message, a reaction, an
     * edit…), by the target's id: the same as a conversation update.
     *
     * @param  array<string, mixed>  $payload
     */
    public function whatsappChatUpdated(array $payload = []): void
    {
        $chatId = $payload['chat_id'] ?? null;

        $conversationId = is_string($chatId) ? ChatConversation::query()
            ->ofUser($this->user())
            ->where('conversable_type', (new WhatsappTarget)->getMorphClass())
            ->whereIn('conversable_id', WhatsappTarget::query()->where('friendly_id', $chatId)->select('id'))
            ->value('friendly_id') : null;

        $this->conversationUpdated(['conversation_id' => $conversationId]);
    }

    public function accountSynced(): void
    {
        unset($this->providers);
        $this->refreshList();
    }

    /**
     * Has the AI prioritize the unread conversations picked in the list
     * (RequestChatTriage), in the background.
     *
     * @param  array<int, mixed>  $conversationIds
     */
    public function triageConversations(array $conversationIds, RequestChatTriage $requestTriage): void
    {
        $this->notice = null;
        $this->error = null;

        try {
            $count = $requestTriage($this->user(), $conversationIds);
        } catch (AiProviderNotConfigured) {
            $this->error = __('No AI provider is set up. Ask the administrator.');

            return;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        } catch (ChatProviderException $exception) {
            $this->error = $exception->getMessage();

            return;
        }

        $this->notice = trans_choice('{1} The AI is prioritizing 1 conversation. The list sorts by it under “AI”.|[2,*] The AI is prioritizing :count conversations. The list sorts by it under “AI”.', $count, ['count' => $count]);
        $this->refreshConversation();
    }

    /**
     * Does one thing to the conversations picked in the list
     * (ApplyChatConversationsBulkAction): read, unread, archive, mute, clear
     * the user's or the AI's priority…
     *
     * @param  array<int, mixed>  $conversationIds
     */
    public function bulkAction(string $action, array $conversationIds, ApplyChatConversationsBulkAction $applyBulkAction): void
    {
        $this->notice = null;
        $this->error = null;

        try {
            ['done' => $done, 'failed' => $failed] = $applyBulkAction($this->user(), $action, $conversationIds);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        if ($failed > 0) {
            $this->error = trans_choice('{1} The channel did not take 1 conversation. Try it again in a moment.|[2,*] The channels did not take :count conversations. Try them again in a moment.', $failed, ['count' => $failed]);
        } else {
            $this->notice = trans_choice('{0} Nothing to change in the conversations picked.|{1} Done in 1 conversation.|[2,*] Done in :count conversations.', $done, ['count' => $done]);
        }

        if (in_array($action, ['unread', 'archive'], true) && in_array($this->conversationId, $conversationIds, true)) {
            $this->closeConversation();
        }

        $this->refreshConversation();
    }

    /**
     * The AI answered a triage, or gave up (`ChatConversationsTriaged`).
     *
     * @param  array{failed?: bool}  $payload
     */
    public function conversationsTriaged(array $payload = []): void
    {
        if ($payload['failed'] ?? false) {
            $this->error = __('The AI could not prioritize the conversations. Try again in a moment.');
        }

        $this->refreshConversation();
    }

    public function rendered(): void
    {
        if ($this->listChanged) {
            $this->listChanged = false;
            $this->renderIsland('conversations');
        }
    }

    public function render(): View
    {
        return view('livewire.chats.chats-page')->title(__('Chats'));
    }

    private function driver(): ChatProviderDriver
    {
        /** @var ChatConversation $conversation */
        $conversation = $this->conversation;

        return app(ChatProviderRegistry::class)->for($conversation);
    }

    private function messageById(string $messageId): ?ChatMessageData
    {
        return $this->messages->first(fn (ChatMessageData $message): bool => $message->id === $messageId)
            ?? $this->thread->first(fn (ChatMessageData $message): bool => $message->id === $messageId);
    }

    private function markRead(ChatConversation $conversation): void
    {
        try {
            app(MarkChatConversationRead::class)($this->user(), $conversation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What a scheduling dialog starts with: once, tomorrow at 9.
     */
    private function resetRecurrence(RecurrencePicker $recurrence): void
    {
        $recurrence->load($this->user()->timezone);
        $recurrence->frequency = RecurrenceFrequency::ONCE;
        $recurrence->starts_at = now()->setTimezone($recurrence->timezone)->addDay()->setTime(9, 0)->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
    }

    private function refreshConversation(): void
    {
        unset($this->conversation, $this->capabilities);
        $this->refreshList();
    }

    /**
     * Sends the list again: it is an island (`conversations`), which the
     * rest of the screen's updates leave as it is. It goes once the action
     * is done (rendered()), with what the action left.
     */
    private function refreshList(): void
    {
        unset($this->conversations, $this->archivedCount);
        $this->listChanged = true;
    }

    /**
     * Runs an Action, keeping what the channel refused as the error shown.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|null
     */
    private function guard(callable $call): mixed
    {
        $this->error = null;

        try {
            return $call();
        } catch (ChatProviderException $exception) {
            $this->error = $exception->getMessage();

            return null;
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
