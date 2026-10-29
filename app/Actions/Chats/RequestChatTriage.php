<?php

namespace App\Actions\Chats;

use App\Exceptions\AiProviderNotConfigured;
use App\Jobs\TriageChatConversations;
use App\Models\AiPurpose;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Ai\AiRoute;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Asks the AI to triage unread conversations of the chat screen, of any
 * channel, picked by their friendly ids (up to MAX_CONVERSATIONS unread
 * ones): they are marked as waiting for it (`ai_triage_requested_at`, on a
 * WhatsApp chat's target too) and TriageChatConversations does it in the
 * background. The ones picked that are not unread are left out; the
 * priority the user gave them is never touched.
 *
 * Only the user's own conversations, of the channels switched on for them,
 * with the `chats` module, and only with an AI set up for the chat replies.
 */
final readonly class RequestChatTriage
{
    use AuthorizesChats, ChangesChatConversation;

    public const int MAX_CONVERSATIONS = 20;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @param  array<int, mixed>  $conversationIds
     * @return int how many conversations the AI triages
     *
     * @throws AuthorizationException
     * @throws AiProviderNotConfigured
     * @throws ChatProviderException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $conversationIds): int
    {
        $this->authorizeChats($actor);

        /** @var array{conversations: list<string>} $validated */
        $validated = Validator::make(['conversations' => array_values($conversationIds)], [
            'conversations' => ['required', 'array', 'max:'.ApplyChatConversationsBulkAction::MAX_CONVERSATIONS],
            'conversations.*' => ['string', 'distinct'],
        ], [
            'conversations.required' => __('Pick the unread conversations for the AI to prioritize.'),
            'conversations.max' => __('Up to :max conversations at a time.', ['max' => ApplyChatConversationsBulkAction::MAX_CONVERSATIONS]),
        ])->validate();

        if (AiRoute::for(AiPurpose::CHAT_REPLY) === null) {
            throw new AiProviderNotConfigured;
        }

        $conversations = ChatConversation::query()
            ->ofUser($actor)
            ->whereIn('friendly_id', $validated['conversations'])
            ->where(fn ($query) => $query->where('unread_count', '>', 0)->orWhere('marked_unread', true))
            ->with('provider')
            ->get()
            ->filter(fn (ChatConversation $conversation): bool => $actor->hasChatProvider($conversation->provider->slug))
            ->values();

        if ($conversations->isEmpty()) {
            throw ValidationException::withMessages(['conversations' => __('Pick the unread conversations for the AI to prioritize.')]);
        }

        if ($conversations->count() > self::MAX_CONVERSATIONS) {
            throw ValidationException::withMessages(['conversations' => __('The AI prioritizes up to :max conversations at a time.', ['max' => self::MAX_CONVERSATIONS])]);
        }

        ApplyChatConversationsBulkAction::quietly(function () use ($conversations): void {
            foreach ($conversations as $conversation) {
                $this->change($this->registry, $conversation, ['ai_triage_requested_at' => now()]);
            }
        });

        TriageChatConversations::dispatch($actor, array_values($conversations->map(fn (ChatConversation $conversation): int => $conversation->id)->all()));

        return $conversations->count();
    }
}
