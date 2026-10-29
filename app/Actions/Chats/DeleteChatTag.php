<?php

namespace App\Actions\Chats;

use App\Models\ChatTag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Deletes one of the user's tags, off every conversation it was on.
 */
final readonly class DeleteChatTag
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ChatTag $tag): void
    {
        $this->authorizeChats($actor);

        if ($tag->user_id !== $actor->id) {
            throw new AuthorizationException;
        }

        $tag->delete();
    }
}
