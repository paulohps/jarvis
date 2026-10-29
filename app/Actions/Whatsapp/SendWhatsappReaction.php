<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappChatReaction;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reacts to a chat message with an emoji, from the number of its chat, as
 * WhatsApp does: one reaction per person, a new one replaces it, the same
 * one again (or '') removes it. Kept at once in Jarvis (reactor ME); the
 * echo WhatsApp sends back changes nothing.
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class SendWhatsappReaction
{
    use AuthorizesWhatsappModule;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * Returns the emoji the owner now reacts with, null for none.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappChatMessage $message, string $emoji): ?string
    {
        $this->authorizeWhatsapp($actor);
        $chat = $message->target;
        $account = $chat->account;
        Gate::forUser($actor)->authorize('use', $account);

        Validator::make(['emoji' => $emoji], ['emoji' => ['present', 'string', 'max:32']])->validate();

        if (! $account->isOpen()) {
            throw ValidationException::withMessages(['emoji' => __('The WhatsApp account :nickname is not connected. Reconnect it first.', ['nickname' => $account->nickname])]);
        }

        if ($message->isRevoked()) {
            throw ValidationException::withMessages(['emoji' => __('This message was deleted on WhatsApp.')]);
        }

        $current = $message->reactions()->where('reactor', WhatsappChatReaction::ME)->first();
        $emoji = $current?->emoji === $emoji ? '' : $emoji;

        $this->client->sendReaction($account->instance_name, $message->whatsappKey(), $emoji);

        if ($emoji === '') {
            $current?->delete();
        } else {
            WhatsappChatReaction::query()->updateOrCreate(
                ['whatsapp_chat_message_id' => $message->id, 'reactor' => WhatsappChatReaction::ME],
                ['emoji' => $emoji, 'reacted_at' => now()],
            );
        }

        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $chat->friendly_id);

        return $emoji === '' ? null : $emoji;
    }
}
