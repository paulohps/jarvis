<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Puts exactly the given tags (friendly ids of the user's own) on a
 * conversation.
 */
final readonly class SetChatConversationTags
{
    use AuthorizesChats;

    /**
     * @param  list<string>  $tagIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, array $tagIds): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        $tagIds = array_values(array_unique(array_filter($tagIds, 'is_string')));
        $tags = $actor->chatTags()->whereIn('friendly_id', $tagIds)->get();

        if ($tags->count() !== count($tagIds)) {
            throw ValidationException::withMessages(['tags' => __('Pick tags of your own.')]);
        }

        $conversation->tags()->sync($tags->modelKeys());
        $conversation->unsetRelation('tags');

        return $conversation;
    }
}
