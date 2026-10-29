<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Asks WhatsApp for the current picture of a chat (contact or group) when
 * it is opened, since the picture's URL expires after a few weeks and
 * chats that came from messages have none. Asked at most once every
 * CHECK_EVERY_HOURS per chat, and only while the number is connected; a
 * chat without a picture, or that hides it, gets none.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class RefreshWhatsappChatPicture
{
    use AuthorizesWhatsappModule;

    public const int CHECK_EVERY_HOURS = 24;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * Whether the chat's picture is due to be asked for again.
     */
    public static function isDue(WhatsappTarget $chat): bool
    {
        return $chat->picture_checked_at === null
            || $chat->picture_checked_at->lt(now()->subHours(self::CHECK_EVERY_HOURS));
    }

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException when WhatsApp cannot be reached
     */
    public function __invoke(User $actor, WhatsappTarget $chat): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        if (! self::isDue($chat) || ! $chat->account->isOpen()) {
            return $chat;
        }

        $chat->forceFill([
            'picture_url' => $this->client->profilePictureOf($chat->account->instance_name, $chat->jid),
            'picture_checked_at' => now(),
        ])->save();

        return $chat;
    }
}
