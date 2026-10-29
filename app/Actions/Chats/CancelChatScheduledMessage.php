<?php

namespace App\Actions\Chats;

use App\Models\ChatScheduledMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a scheduled message that was not sent yet, or that repeats:
 * its recurrence stops.
 */
final readonly class CancelChatScheduledMessage
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatScheduledMessage $scheduled): ChatScheduledMessage
    {
        $this->authorizeChats($actor);

        if ($scheduled->user_id !== $actor->id) {
            throw new AuthorizationException(__('This scheduled message is not yours.'));
        }

        if (! $scheduled->isPending()) {
            throw ValidationException::withMessages(['scheduled' => __('This message was already sent or cancelled.')]);
        }

        $scheduled->forceFill(['cancelled_at' => now()])->save();
        $scheduled->recurrence?->update(['is_paused' => true]);

        return $scheduled;
    }
}
