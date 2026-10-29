<?php

namespace App\Actions\Chats;

use App\Models\ChatContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Renames one of the user's contacts and keeps its notes.
 */
final readonly class RenameChatContact
{
    use AuthorizesChats;

    public const int MAX_NOTES_LENGTH = 2000;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatContact $contact, ?string $name, ?string $notes = null): ChatContact
    {
        $this->authorizeChats($actor);

        if ($contact->user_id !== $actor->id) {
            throw new AuthorizationException(__('This contact is not yours.'));
        }

        /** @var array{name: string, notes: string|null} $validated */
        $validated = Validator::make(
            ['name' => filled($name) ? mb_trim((string) $name) : null, 'notes' => filled($notes) ? mb_trim((string) $notes) : null],
            ['name' => ['required', 'string', 'max:'.ChatContact::MAX_NAME_LENGTH], 'notes' => ['nullable', 'string', 'max:'.self::MAX_NOTES_LENGTH]],
            attributes: ['name' => __('name'), 'notes' => __('notes')],
        )->validate();

        $contact->fill($validated)->save();

        return $contact;
    }
}
