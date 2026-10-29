<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One thing done to many conversations of the chat screen at once, of any
 * channel, picked by their friendly ids (up to MAX_CONVERSATIONS), through
 * the Actions of a single conversation:
 *
 * - `read`: read, as opening it does (MarkChatConversationRead: the
 *   channel is told when it takes that);
 * - `unread`: marked unread (MarkChatConversationUnread);
 * - `archive` / `unarchive` (ArchiveChatConversation);
 * - `mute` / `unmute`: always, or no more (MuteChatConversation);
 * - `clear_priority`: the priority the user gave them goes;
 * - `clear_ai`: what the AI found about them goes (the user's priority stays).
 *
 * Only the conversations that changed are counted as done; one its
 * channel refuses is counted as failed and the others go on. The conversations are not announced one by one (isRunning()):
 * the screen that asked reloads its list once.
 *
 * Only the user's own conversations, of the channels switched on for them,
 * with the `chats` module.
 */
final readonly class ApplyChatConversationsBulkAction
{
    use AuthorizesChats, ChangesChatConversation;

    public const int MAX_CONVERSATIONS = 50;

    /**
     * @var list<string>
     */
    public const array ACTIONS = ['read', 'unread', 'archive', 'unarchive', 'mute', 'unmute', 'clear_priority', 'clear_ai'];

    private const string RUNNING = 'chats.bulk-action';

    public function __construct(
        private ChatProviderRegistry $registry,
        private MarkChatConversationRead $markRead,
        private MarkChatConversationUnread $markUnread,
        private ArchiveChatConversation $archive,
        private MuteChatConversation $mute,
        private SetChatConversationPriority $setPriority,
    ) {}

    /**
     * Whether a change of many conversations is under way, so that what
     * each one would announce is left to its end.
     */
    public static function isRunning(): bool
    {
        return Context::hasHidden(self::RUNNING);
    }

    /**
     * Runs the callback as a change of many conversations: what each one
     * would announce on its own is left out.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function quietly(callable $callback): mixed
    {
        return Context::scope($callback, hidden: [self::RUNNING => true]);
    }

    /**
     * @param  array<int, mixed>  $conversationIds
     * @return array{done: int, failed: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, string $action, array $conversationIds): array
    {
        $this->authorizeChats($actor);

        /** @var array{action: string, conversations: list<string>} $validated */
        $validated = Validator::make(['action' => $action, 'conversations' => array_values($conversationIds)], [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'conversations' => ['required', 'array', 'max:'.self::MAX_CONVERSATIONS],
            'conversations.*' => ['string', 'distinct'],
        ], [
            'conversations.required' => __('Pick the conversations first.'),
            'conversations.max' => __('Up to :max conversations at a time.', ['max' => self::MAX_CONVERSATIONS]),
        ])->validate();

        $conversations = ChatConversation::query()
            ->ofUser($actor)
            ->whereIn('friendly_id', $validated['conversations'])
            ->with('provider')
            ->get()
            ->filter(fn (ChatConversation $conversation): bool => $actor->hasChatProvider($conversation->provider->slug));

        return self::quietly(function () use ($actor, $conversations, $validated): array {
            $done = 0;
            $failed = 0;

            foreach ($conversations as $conversation) {
                try {
                    $done += (int) $this->apply($actor, $conversation, $validated['action']);
                } catch (ChatProviderException $exception) {
                    report($exception);
                    $failed++;
                }
            }

            return ['done' => $done, 'failed' => $failed];
        });
    }

    /**
     * Whether the conversation changed: one already as asked is left alone.
     *
     * @throws AuthorizationException
     * @throws ChatProviderException
     * @throws ValidationException
     */
    private function apply(User $actor, ChatConversation $conversation, string $action): bool
    {
        $isAlready = match ($action) {
            'read' => ! $conversation->isUnread(),
            'unread' => $conversation->isUnread(),
            'archive' => $conversation->isArchived(),
            'unarchive' => ! $conversation->isArchived(),
            'mute' => $conversation->isMuted(),
            'unmute' => ! $conversation->isMuted(),
            'clear_priority' => $conversation->priority === null,
            'clear_ai' => $conversation->ai_urgency === null && $conversation->ai_triage_requested_at === null,
            default => throw new InvalidArgumentException("Unknown bulk action [{$action}]."),
        };

        if ($isAlready) {
            return false;
        }

        match ($action) {
            'read' => ($this->markRead)($actor, $conversation),
            'unread' => ($this->markUnread)($actor, $conversation),
            'archive', 'unarchive' => ($this->archive)($actor, $conversation, $action === 'archive'),
            'mute', 'unmute' => ($this->mute)($actor, $conversation, $action === 'mute' ? 'always' : null),
            'clear_priority' => ($this->setPriority)($actor, $conversation, null),
            'clear_ai' => $this->change($this->registry, $conversation, array_fill_keys(ChatConversation::AI_TRIAGE_COLUMNS, null)),
        };

        return true;
    }
}
