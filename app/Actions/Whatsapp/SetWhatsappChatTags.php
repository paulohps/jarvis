<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Puts on a chat or group exactly the given tags of the user's (by their
 * friendly ids), taking off the others; ids that are not the user's tags
 * are ignored.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SetWhatsappChatTags
{
    use AuthorizesWhatsappModule;

    /**
     * @param  list<string>  $tagIds
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, array $tagIds): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        $chat->tags()->sync($actor->whatsappTags()->whereIn('friendly_id', $tagIds)->pluck('id')->all());

        return $chat;
    }
}
