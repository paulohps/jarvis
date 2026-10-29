<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Takes a conversation off its contact; a contact left with no
 * conversation is removed.
 */
final readonly class UnlinkChatContact
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, ChatConversation $conversation): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        DB::transaction(function () use ($conversation): void {
            $contact = $conversation->contact;

            $conversation->forceFill(['chat_contact_id' => null])->save();
            $conversation->unsetRelation('contact');

            if ($contact !== null && ! $contact->conversations()->exists()) {
                $contact->delete();
            }
        });

        return $conversation;
    }
}
