<?php

namespace App\Actions\Chats;

use App\Models\ChatContact;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Says who a conversation belongs to, so the same person's conversations
 * on different channels (Jessé on Slack for work, on WhatsApp for the
 * rest) sit under one contact (Key Concept "Chat contact"). The target is
 * another conversation of the user (both go under that one's contact, or
 * a new contact named after it), one of their contacts, or a name for a
 * new contact. A conversation leaves its earlier contact; a contact left
 * with no conversation is removed.
 */
final readonly class LinkChatContact
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ChatConversation|ChatContact|string $target): ChatContact
    {
        $this->authorizeConversation($actor, $conversation);

        if ($target instanceof ChatConversation) {
            $this->authorizeConversation($actor, $target);

            if ($target->is($conversation)) {
                throw ValidationException::withMessages(['conversation' => __('Pick another conversation.')]);
            }
        } elseif ($target instanceof ChatContact && $target->user_id !== $actor->id) {
            throw new AuthorizationException(__('This contact is not yours.'));
        }

        return DB::transaction(function () use ($actor, $conversation, $target): ChatContact {
            $contact = match (true) {
                $target instanceof ChatContact => $target,
                $target instanceof ChatConversation => $target->contact ?? $this->create($actor, $target->displayName()),
                default => $this->create($actor, $target),
            };

            if ($target instanceof ChatConversation && $target->chat_contact_id !== $contact->id) {
                $target->forceFill(['chat_contact_id' => $contact->id])->save();
            }

            $this->move($conversation, $contact);

            return $contact;
        });
    }

    private function create(User $actor, string $name): ChatContact
    {
        /** @var array{name: string} $validated */
        $validated = Validator::make(
            ['name' => mb_trim($name)],
            ['name' => ['required', 'string', 'max:'.ChatContact::MAX_NAME_LENGTH]],
            attributes: ['name' => __('name')],
        )->validate();

        return ChatContact::query()->create(['user_id' => $actor->id, 'name' => $validated['name']]);
    }

    private function move(ChatConversation $conversation, ChatContact $contact): void
    {
        $previous = $conversation->contact;

        if ($previous?->is($contact)) {
            return;
        }

        $conversation->forceFill(['chat_contact_id' => $contact->id])->save();
        $conversation->setRelation('contact', $contact);

        if ($previous !== null && ! $previous->conversations()->exists()) {
            $previous->delete();
        }
    }
}
