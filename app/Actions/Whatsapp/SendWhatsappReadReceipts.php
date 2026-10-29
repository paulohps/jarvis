<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Tells WhatsApp that the open chat was read, as WhatsApp Web does when the
 * chat is on screen: the messages the contact sent after `read_up_to`, the
 * latest MAX_MESSAGES of them. Only when the chat sends read receipts
 * (WhatsappTarget::sendsReadReceipts(): off by default) and its number is
 * connected; otherwise the chat is read in Jarvis only. WhatsApp failing
 * is reported and leaves `read_up_to` as it was, to try on the next look.
 *
 * Returns how many messages WhatsApp was told about.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SendWhatsappReadReceipts
{
    use AuthorizesWhatsappModule;

    public const int MAX_MESSAGES = 50;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat): int
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        if (! $chat->sendsReadReceipts() || ! $chat->account->isOpen()) {
            return 0;
        }

        $unread = $chat->chatMessages()
            ->where('from_me', false)
            ->when($chat->read_up_to !== null, fn ($query) => $query->where('sent_at', '>', $chat->read_up_to))
            ->orderByDesc('sent_at')
            ->limit(self::MAX_MESSAGES)
            ->get();

        if ($unread->isEmpty()) {
            return 0;
        }

        try {
            $this->client->markRead($chat->account->instance_name, $unread->map(fn (WhatsappChatMessage $message): array => [
                'id' => $message->external_id,
                'remoteJid' => $chat->jid,
                'participant' => $message->sender_jid,
            ])->values()->all());
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }

        $chat->forceFill(['read_up_to' => $unread->max('sent_at')])->save();

        return $unread->count();
    }
}
