<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What the Actions that change a chat on WhatsApp check first: the actor
 * has the `whatsapp` module and owns the chat's number, and, for what goes
 * to WhatsApp, the number is connected.
 */
trait AuthorizesWhatsappChat
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     */
    private function authorizeChat(User $actor, WhatsappTarget $chat): void
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException when the number is not connected, under `$field`
     */
    private function authorizeConnectedChat(User $actor, WhatsappTarget $chat, string $field): void
    {
        $this->authorizeChat($actor, $chat);

        if (! $chat->account->isOpen()) {
            throw ValidationException::withMessages([$field => __('The WhatsApp account :nickname is not connected. Reconnect it first.', ['nickname' => $chat->account->nickname])]);
        }
    }

    /**
     * @throws ValidationException when the chat is no group, under `$field`
     */
    private function ensureGroup(WhatsappTarget $chat, string $field): void
    {
        if (! $chat->isGroup()) {
            throw ValidationException::withMessages([$field => __('Only a group can do this.')]);
        }
    }
}
