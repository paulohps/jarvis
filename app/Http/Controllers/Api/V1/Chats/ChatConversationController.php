<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Actions\Chats\ArchiveChatConversation;
use App\Actions\Chats\CreateChatReminder;
use App\Actions\Chats\DraftChatReply;
use App\Actions\Chats\FindsChatConversations;
use App\Actions\Chats\LinkChatContact;
use App\Actions\Chats\MarkChatConversationRead;
use App\Actions\Chats\MarkChatConversationUnread;
use App\Actions\Chats\MuteChatConversation;
use App\Actions\Chats\PinChatConversation;
use App\Actions\Chats\ScheduleChatMessage;
use App\Actions\Chats\SetChatConversationNickname;
use App\Actions\Chats\SetChatConversationPriority;
use App\Actions\Chats\SetChatConversationTags;
use App\Actions\Chats\SyncChatConversation;
use App\Actions\Chats\UnlinkChatContact;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatConversationResource;
use App\Http\Resources\ChatScheduledMessageResource;
use App\Http\Resources\ReminderResource;
use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The conversations of the chat screen for the apps: the list, one
 * conversation with what its channel supports, and what Jarvis adds on
 * top (pin, priority, nickname, archive, mute, unread, tags, contact,
 * reminders, scheduled messages, the AI draft, sync). The messages
 * themselves are in ChatMessageController.
 */
class ChatConversationController extends Controller
{
    use FindsChatConversations;

    public const int PER_PAGE = 50;

    /**
     * `GET /api/v1/chats/conversations`: by the latest message (pinned
     * first), or by priority with `sort=priority`; `provider`, `tag`
     * (friendly id), `search` and `archived=1` narrow it.
     */
    public function index(Request $request, ChatProviderRegistry $registry): AnonymousResourceCollection
    {
        $user = $this->actor($request);
        $search = mb_trim((string) $request->query('search', ''));
        $providers = $registry->providersFor($user);

        $conversations = ChatConversation::query()
            ->ofUser($user)
            ->whereIn('chat_provider_id', $request->filled('provider') ? $providers->where('slug', $request->query('provider'))->modelKeys() : $providers->modelKeys())
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('nickname', "%{$search}%")))
            ->when($request->filled('tag'), fn ($query) => $query->whereHas('tags', fn ($query) => $query->where('friendly_id', $request->query('tag'))))
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->with(['provider', 'tags', 'contact'])
            ->when($request->query('sort') === 'priority', fn ($query) => $query->priorityFirst(), fn ($query) => $query->recentFirst())
            ->paginate(self::PER_PAGE);

        return ChatConversationResource::collection($conversations);
    }

    /**
     * `GET /api/v1/chats/conversations/{conversation}`: with what the
     * channel supports on it and its pending scheduled messages.
     */
    public function show(Request $request, string $conversation, ChatProviderRegistry $registry): JsonResponse
    {
        $found = $this->conversationOf($this->actor($request), $conversation);
        $driver = $registry->for($found);

        return (new ChatConversationResource($found))->withChannel($driver->capabilities($found), $driver->externalUrl($found))
            ->additional(['scheduled' => ChatScheduledMessageResource::collection($found->scheduledMessages()->pending()->soonestFirst()->with('recurrence.frequency')->get())])
            ->response();
    }

    /**
     * `PATCH /api/v1/chats/conversations/{conversation}`: any of `pinned`,
     * `priority` (1–5 or null), `nickname`, `archived`, `mute` (`8h`, `1w`,
     * `always` or null), `unread` (true marks it, false reads it) and
     * `tags` (friendly ids).
     */
    public function update(Request $request, string $conversation): ChatConversationResource
    {
        $user = $this->actor($request);
        $found = $this->conversationOf($user, $conversation);

        try {
            $this->apply($request, $user, $found);
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['conversation' => $exception->getMessage()]);
        }

        return new ChatConversationResource($this->conversationOf($user, $conversation));
    }

    /**
     * @throws ChatProviderException when the channel refuses a change it keeps itself (WhatsApp, the phone)
     */
    private function apply(Request $request, User $user, ChatConversation $found): void
    {
        if ($request->has('pinned')) {
            app(PinChatConversation::class)($user, $found, $request->boolean('pinned'));
        }

        if ($request->has('priority')) {
            app(SetChatConversationPriority::class)($user, $found, $request->input('priority') === null ? null : (int) $request->input('priority'));
        }

        if ($request->has('nickname')) {
            app(SetChatConversationNickname::class)($user, $found, $request->input('nickname'));
        }

        if ($request->has('archived')) {
            app(ArchiveChatConversation::class)($user, $found, $request->boolean('archived'));
        }

        if ($request->has('mute')) {
            app(MuteChatConversation::class)($user, $found, $request->input('mute'));
        }

        if ($request->has('unread')) {
            $request->boolean('unread') ? app(MarkChatConversationUnread::class)($user, $found) : app(MarkChatConversationRead::class)($user, $found);
        }

        if ($request->has('tags')) {
            app(SetChatConversationTags::class)($user, $found, array_values(array_filter((array) $request->input('tags', []), 'is_string')));
        }
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/read`.
     */
    public function read(Request $request, string $conversation, MarkChatConversationRead $markRead): ChatConversationResource
    {
        $user = $this->actor($request);

        return new ChatConversationResource($markRead($user, $this->conversationOf($user, $conversation)));
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/sync`: the name,
     * picture and latest messages from the channel, or the ones before
     * `older_than` (a message id).
     */
    public function sync(Request $request, string $conversation, SyncChatConversation $sync): ChatConversationResource
    {
        $user = $this->actor($request);

        try {
            return new ChatConversationResource($sync($user, $this->conversationOf($user, $conversation), $request->input('older_than')));
        } catch (ChatProviderException $exception) {
            throw ValidationException::withMessages(['conversation' => $exception->getMessage()]);
        }
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/draft`:
     * `{ message_ids?, instruction? }`; without messages the latest
     * received ones are answered, and with an `instruction` only (nothing
     * received) a new message is written.
     */
    public function draft(Request $request, string $conversation, DraftChatReply $draftReply): JsonResponse
    {
        $user = $this->actor($request);
        $found = $this->conversationOf($user, $conversation);
        $messageIds = array_values(array_filter((array) $request->input('message_ids', []), 'is_string'));

        try {
            $text = $messageIds === [] && $request->boolean('compose')
                ? $draftReply->fromPrompt($user, $found, $request->input('instruction'))
                : $draftReply($user, $found, $messageIds, $request->input('instruction'));
        } catch (AiProviderNotConfigured) {
            return response()->json(['message' => __('No AI provider is set up. Ask the administrator.')], 503);
        } catch (AiAnswerCutShort $exception) {
            report($exception);

            return response()->json(['message' => __('The message came out too long and was cut short. Ask for a shorter one, or try again.')], 502);
        }

        return response()->json(['data' => ['text' => $text]]);
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/reminders`:
     * `{ message_ids?, title, due_at?, workspace? }`.
     */
    public function remind(Request $request, string $conversation, CreateChatReminder $createReminder): JsonResponse
    {
        $user = $this->actor($request);

        $reminder = $createReminder($user, $this->conversationOf($user, $conversation), array_values(array_filter((array) $request->input('message_ids', []), 'is_string')), [
            'title' => $request->input('title'),
            'due_at' => $request->input('due_at'),
            'workspace' => $request->input('workspace'),
        ]);

        return (new ReminderResource($reminder))->response()->setStatusCode(201);
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/schedule`:
     * `{ body | ai_prompt | poll, send_at | recurrence, thread_id? }`.
     */
    public function schedule(Request $request, string $conversation, ScheduleChatMessage $scheduleMessage): JsonResponse
    {
        $user = $this->actor($request);

        try {
            $scheduled = $scheduleMessage($user, $this->conversationOf($user, $conversation), $request->only(['body', 'ai_prompt', 'poll', 'thread_id', 'send_at', 'recurrence']));
        } catch (AiProviderNotConfigured) {
            return response()->json(['message' => __('No AI provider is set up. Ask the administrator.')], 503);
        }

        return (new ChatScheduledMessageResource($scheduled))->response()->setStatusCode(201);
    }

    /**
     * `POST /api/v1/chats/conversations/{conversation}/contact`: one of
     * `conversation` (another conversation's id), `contact` (a contact's
     * id) or `name` (a new contact).
     */
    public function link(Request $request, string $conversation, LinkChatContact $link): ChatConversationResource
    {
        $user = $this->actor($request);
        $found = $this->conversationOf($user, $conversation);

        $target = match (true) {
            $request->filled('conversation') => $this->conversationOf($user, (string) $request->input('conversation')),
            $request->filled('contact') => ChatContact::query()->where('user_id', $user->id)->byFriendlyId((string) $request->input('contact'))->firstOrFail(),
            default => (string) $request->input('name', ''),
        };

        $link($user, $found, $target);

        return new ChatConversationResource($this->conversationOf($user, $conversation));
    }

    /**
     * `DELETE /api/v1/chats/conversations/{conversation}/contact`.
     */
    public function unlink(Request $request, string $conversation, UnlinkChatContact $unlink): ChatConversationResource
    {
        $user = $this->actor($request);

        $unlink($user, $this->conversationOf($user, $conversation));

        return new ChatConversationResource($this->conversationOf($user, $conversation));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
