<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Forwards messages of a chat to other chats of the same number, in the
 * order they were sent: each goes again as what it is (a text, or its file
 * with its caption; an audio as a voice note), through the WhatsApp
 * service (SendWhatsAppMessage, source `chat`), so it shows in the history
 * and in each chat once sent.
 *
 * The Evolution API has no forwarding of its own, so WhatsApp does not mark
 * them "Forwarded". A file not in Jarvis yet is fetched first
 * (LoadWhatsappChatMedia); each forward gets its own copy of it. Messages
 * deleted on WhatsApp, and ones without a text or a file (a location, a
 * contact, a poll), are refused.
 *
 * Up to MAX_MESSAGES messages to MAX_CHATS chats, as WhatsApp allows. Only
 * the owner of the account, with the `whatsapp` module.
 */
final readonly class ForwardWhatsappChatMessages
{
    use AuthorizesWhatsappModule;

    public const int MAX_MESSAGES = 20;

    public const int MAX_CHATS = 5;

    public function __construct(
        private SendWhatsAppMessage $send,
        private LoadWhatsappChatMedia $loadMedia,
    ) {}

    /**
     * @param  list<WhatsappChatMessage>  $messages  of one chat
     * @param  list<WhatsappTarget>  $chats  of the same number
     * @return Collection<int, WhatsappDelivery>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $messages, array $chats): Collection
    {
        $this->authorizeWhatsapp($actor);

        if ($messages === [] || count($messages) > self::MAX_MESSAGES) {
            throw ValidationException::withMessages(['messages' => __('Pick from 1 to :max messages.', ['max' => self::MAX_MESSAGES])]);
        }

        if ($chats === [] || count($chats) > self::MAX_CHATS) {
            throw ValidationException::withMessages(['chats' => __('Forward to 1 to :max chats.', ['max' => self::MAX_CHATS])]);
        }

        $source = $messages[0]->target;
        $account = $source->account;
        Gate::forUser($actor)->authorize('use', $account);

        if (array_any($messages, fn (WhatsappChatMessage $message): bool => $message->whatsapp_target_id !== $source->id)) {
            throw ValidationException::withMessages(['messages' => __('Pick messages of the same chat.')]);
        }

        if (array_any($chats, fn (WhatsappTarget $chat): bool => $chat->whatsapp_account_id !== $account->id)) {
            throw ValidationException::withMessages(['chats' => __('Forward to chats of the same number.')]);
        }

        if (! $account->isOpen()) {
            throw ValidationException::withMessages(['chats' => __('The WhatsApp account :nickname is not connected. Reconnect it first.', ['nickname' => $account->nickname])]);
        }

        usort($messages, fn (WhatsappChatMessage $a, WhatsappChatMessage $b): int => [$a->sent_at->getTimestamp(), $a->id] <=> [$b->sent_at->getTimestamp(), $b->id]);

        $contents = array_map(fn (WhatsappChatMessage $message): array => $this->contentOf($actor, $message), $messages);
        $deliveries = collect();

        foreach ($chats as $chat) {
            foreach ($contents as $content) {
                $media = $content['media'] !== null
                    ? [...$content['media'], 'path' => $this->copy($account->friendly_id, $content['media'])]
                    : null;

                $deliveries->push(($this->send)($account, $chat, $content['body'], WhatsappDeliverySource::CHAT, triggeredBy: $actor, media: $media));
            }
        }

        return $deliveries;
    }

    /**
     * What the message sends again: its text, or its file and caption.
     *
     * @return array{body: string, media: array{path: string, mime: string, name: string|null}|null}
     *
     * @throws ValidationException
     */
    private function contentOf(User $actor, WhatsappChatMessage $message): array
    {
        if ($message->isRevoked()) {
            throw ValidationException::withMessages(['messages' => __('A message deleted on WhatsApp is not forwarded.')]);
        }

        if ($message->mediaKind() === null) {
            if (blank($message->body)) {
                throw ValidationException::withMessages(['messages' => __('Only texts and files are forwarded.')]);
            }

            return ['body' => (string) $message->body, 'media' => null];
        }

        try {
            $loaded = ($this->loadMedia)($actor, $message);
        } catch (EvolutionApiException) {
            $loaded = false;
        }

        if (! $loaded || $message->media_path === null) {
            throw ValidationException::withMessages(['messages' => __('WhatsApp no longer has the file of a message. See it on the phone.')]);
        }

        return [
            'body' => $message->mediaKind() === 'audio' ? '' : (string) $message->body,
            'media' => ['path' => $message->media_path, 'mime' => (string) $message->media_mime, 'name' => $message->media_name],
        ];
    }

    /**
     * A copy of the file for one forward, so each chat keeps its own.
     *
     * @param  array{path: string, mime: string, name: string|null}  $media
     */
    private function copy(string $accountId, array $media): string
    {
        return WhatsappChatMedia::storeOutgoing($accountId, (string) WhatsappChatMedia::disk()->get($media['path']), $media['mime']);
    }
}
