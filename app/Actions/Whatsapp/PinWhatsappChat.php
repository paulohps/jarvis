<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Pins a chat to the top of the WhatsApp screen, or unpins it. Unlike
 * WhatsApp (3 at most), any number of chats may be pinned; the latest
 * pinned comes first. Pinning again keeps the original order.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class PinWhatsappChat
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, bool $pinned): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        if ($pinned !== $chat->isPinned()) {
            $chat->update(['pinned_at' => $pinned ? now() : null]);
        }

        return $chat;
    }
}
