<?php

namespace App\Actions\Whatsapp;

use App\Jobs\SendWhatsappPresence;
use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * While the user types on the chat screen, shows "typing…" to the contact
 * on WhatsApp for TYPING_MILLISECONDS (the screen asks again while the
 * typing goes on). Only when the chat sends typing
 * (WhatsappTarget::sendsTyping(): off by default) and its number is
 * connected. Returns whether it was shown.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class ShowWhatsappTyping
{
    use AuthorizesWhatsappModule;

    public const int TYPING_MILLISECONDS = 6000;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat): bool
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        if (! $chat->sendsTyping() || ! $chat->account->isOpen()) {
            return false;
        }

        SendWhatsappPresence::dispatchAfterResponse($chat->account, $chat->jid, 'composing', self::TYPING_MILLISECONDS);

        return true;
    }
}
