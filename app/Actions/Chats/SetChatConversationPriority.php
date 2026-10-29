<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets the priority of a conversation, from ChatConversation::MIN_PRIORITY
 * (1, the highest) to MAX_PRIORITY (5), shown in the color the user set
 * for that priority (the palette of the WhatsApp screen, shared). Null
 * clears it.
 */
final readonly class SetChatConversationPriority
{
    use AuthorizesChats, ChangesChatConversation;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ?int $priority): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        /** @var array{priority: int|null} $validated */
        $validated = Validator::make(
            ['priority' => $priority],
            ['priority' => ['nullable', 'integer', 'between:'.ChatConversation::MIN_PRIORITY.','.ChatConversation::MAX_PRIORITY]],
            attributes: ['priority' => __('priority')],
        )->validate();

        $this->change($this->registry, $conversation, ['priority' => $validated['priority']]);

        return $conversation;
    }
}
