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
 * Gives a conversation a name of the user's own on the chat screen, up to
 * ChatConversation::MAX_NICKNAME_LENGTH, or takes it back (blank): the
 * provider's name shows again.
 */
final readonly class SetChatConversationNickname
{
    use AuthorizesChats, ChangesChatConversation;

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ?string $nickname): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        /** @var array{nickname: string|null} $validated */
        $validated = Validator::make(
            ['nickname' => filled($nickname) ? mb_trim((string) $nickname) : null],
            ['nickname' => ['nullable', 'string', 'max:'.ChatConversation::MAX_NICKNAME_LENGTH]],
            attributes: ['nickname' => __('name')],
        )->validate();

        $this->change($this->registry, $conversation, ['nickname' => $validated['nickname']]);

        return $conversation;
    }
}
