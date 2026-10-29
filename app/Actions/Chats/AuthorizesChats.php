<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\Module;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * What the chat layer's Actions check first: the actor has the `chats`
 * module (the same check `module:chats` makes on the routes), may use the
 * provider (switched on for them by the super admin) and owns the
 * conversation. Repeated in the Actions so the web and the API cannot
 * drift apart.
 */
trait AuthorizesChats
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeChats(User $actor): void
    {
        Gate::forUser($actor)->authorize('access-module', Module::CHATS);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeProvider(User $actor, string $providerSlug): void
    {
        $this->authorizeChats($actor);

        if (! $actor->hasChatProvider($providerSlug)) {
            throw new AuthorizationException(__('This chat channel is not switched on for you.'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeConversation(User $actor, ChatConversation $conversation): void
    {
        $this->authorizeProvider($actor, $conversation->provider->slug);

        if ($conversation->user_id !== $actor->id) {
            throw new AuthorizationException(__('This conversation is not yours.'));
        }
    }
}
