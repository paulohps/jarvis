<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Stars or unstars a chat message, to find it again in the starred list.
 * Kept in Jarvis only: the Evolution API has no way to star a message on
 * the phone.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class StarWhatsappChatMessage
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappChatMessage $message, bool $starred): WhatsappChatMessage
    {
        $this->authorizeWhatsapp($actor);
        $account = $message->target->account;
        Gate::forUser($actor)->authorize('use', $account);

        if ($starred !== ($message->starred_at !== null)) {
            $message->update(['starred_at' => $starred ? now() : null]);
            WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $message->target->friendly_id);
        }

        return $message;
    }
}
