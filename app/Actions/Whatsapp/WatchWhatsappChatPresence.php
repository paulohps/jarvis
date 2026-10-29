<?php

namespace App\Actions\Whatsapp;

use App\Jobs\SendWhatsappPresence;
use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Asks WhatsApp for the presence of a chat the user opens (who is typing,
 * recording or online), which then comes as `presence.update` webhooks
 * (HandleEvolutionWebhook). The Evolution API subscribes only when sending
 * a presence, so this sends `paused` for no time, which the contact does not
 * see. Only while the number is connected, and at most once every
 * WATCH_SECONDS per chat, as the apps open and poll a chat often. Returns
 * whether it was asked.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class WatchWhatsappChatPresence
{
    use AuthorizesWhatsappModule;

    public const int WATCH_SECONDS = 600;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat): bool
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        if (! $chat->account->isOpen() || ! Cache::add('whatsapp:presence-watch:'.$chat->id, true, self::WATCH_SECONDS)) {
            return false;
        }

        SendWhatsappPresence::dispatchAfterResponse($chat->account, $chat->jid, 'paused', 0);

        return true;
    }
}
