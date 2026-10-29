<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTag;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Deletes one of the user's chat tags, taking it off every chat.
 *
 * Only the tag's owner, with the `whatsapp` module.
 */
final readonly class DeleteWhatsappTag
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $user, WhatsappTag $tag): void
    {
        $this->authorizeWhatsapp($user);

        if ($tag->user_id !== $user->id) {
            throw new AuthorizationException;
        }

        $tag->delete();
    }
}
