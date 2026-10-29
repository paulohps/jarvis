<?php

namespace App\Jobs;

use App\Actions\Whatsapp\RecordWhatsappChatMessages;
use App\Exceptions\EvolutionApiException;
use App\Models\DeliveryStatus;
use App\Models\WhatsappDelivery;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\WhatsappChatMedia;
use App\Services\Whatsapp\WhatsappDeliveryFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use RuntimeException;
use Throwable;

/**
 * Sends one `whatsapp_deliveries` row through the Evolution API (US-7.4), on
 * the `whatsapp` queue with 3 attempts (backoff 30s, then 120s). Queued only
 * by `SendWhatsAppMessage`.
 *
 * On success the delivery is `sent` with the WhatsApp message id and
 * `sent_at`. An account that stopped being `open` meanwhile fails at once,
 * without calling the API. When the last attempt fails, the delivery is
 * `failed` with the error and the owner is told (`whatsapp_message_failed`).
 * A delivery with a file sends it as a media, the body as its caption (or
 * as a sticker); one with a poll, location or contacts in its `payload`
 * sends that instead of the body; the quote and mentions of the payload go
 * with a text, a media, a sticker, a poll or a location. A
 * delivery already sent is never sent again. What was sent goes into the
 * chat with the target (RecordWhatsappChatMessages, as a live message from
 * the number), so the WhatsApp screen shows it.
 */
#[Tries(3)]
#[Backoff(30, 120)]
#[DeleteWhenMissingModels]
class DeliverWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'whatsapp';

    public function __construct(public WhatsappDelivery $delivery)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EvolutionApiClient $client, RecordWhatsappChatMessages $recordMessages): void
    {
        $delivery = $this->delivery;

        if ($delivery->delivery_status_id !== DeliveryStatus::idFor(DeliveryStatus::PENDING)) {
            return;
        }

        $delivery->forceFill(['attempts' => $this->attempts()])->save();

        $account = $delivery->account;

        if (! $account->isOpen()) {
            $this->fail(new RuntimeException(WhatsappDeliveryFailure::accountNotOpenMessage($account)));

            return;
        }

        $messageId = $this->sendContent($client, $delivery);

        $delivery->forceFill([
            'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::SENT),
            'external_message_id' => $messageId !== '' ? $messageId : null,
            'error_message' => null,
            'sent_at' => now(),
        ])->save();

        if ($messageId !== '') {
            $this->record($recordMessages, $delivery, $messageId);
        }
    }

    /**
     * Sends what the delivery holds and returns the WhatsApp message id.
     *
     * @throws EvolutionApiException
     */
    private function sendContent(EvolutionApiClient $client, WhatsappDelivery $delivery): string
    {
        $instance = $delivery->account->instance_name;
        $jid = $delivery->target->jid;
        $payload = $delivery->payload ?? [];
        $context = array_filter([
            'quoted' => isset($payload['quoted']) ? array_intersect_key($payload['quoted'], ['key' => true, 'message' => true]) : null,
            'mentioned' => $payload['mentioned'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return match (true) {
            isset($payload['poll']) => $client->sendPoll($instance, $jid, $payload['poll']['name'], $payload['poll']['options'], $payload['poll']['selectable_count'], $context),
            isset($payload['location']) => $client->sendLocation($instance, $jid, $payload['location']['latitude'], $payload['location']['longitude'], $payload['location']['name'] ?? null, $payload['location']['address'] ?? null, $context),
            isset($payload['contacts']) => $client->sendContacts($instance, $jid, $payload['contacts']),
            $delivery->media_path !== null => $this->sendMedia($client, $delivery, $context),
            default => $client->sendText($instance, $jid, $delivery->body, $context),
        };
    }

    /**
     * Sends the delivery's file: an audio as a voice note, a sticker as
     * such, an image or a video as such, anything else as a document; the
     * body is the caption.
     *
     * @param  array{quoted?: array<string, mixed>, mentioned?: list<string>}  $context
     *
     * @throws EvolutionApiException
     */
    private function sendMedia(EvolutionApiClient $client, WhatsappDelivery $delivery, array $context): string
    {
        $instance = $delivery->account->instance_name;
        $jid = $delivery->target->jid;
        $mime = (string) $delivery->media_mime;
        $content = (string) WhatsappChatMedia::disk()->get((string) $delivery->media_path);

        if (($delivery->payload['sticker'] ?? false) === true) {
            return $client->sendSticker($instance, $jid, $mime, $content, $context);
        }

        return match (self::mediaTypeFor($mime)) {
            'audio' => $client->sendAudio($instance, $jid, $content),
            default => $client->sendMedia($instance, $jid, self::mediaTypeFor($mime), $mime, $content, $delivery->body, $delivery->media_name, $context),
        };
    }

    /**
     * How WhatsApp is sent a file of the type: `image`, `video`, `audio` or
     * `document`.
     */
    public static function mediaTypeFor(string $mime): string
    {
        return match (true) {
            in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) => 'image',
            in_array($mime, ['video/mp4', 'video/3gpp'], true) => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * Puts what was sent into the chat with the target, the file included.
     */
    private function record(RecordWhatsappChatMessages $recordMessages, WhatsappDelivery $delivery, string $messageId): void
    {
        [$type, $content] = $this->contentOf($delivery);
        $payload = $delivery->payload ?? [];
        $quoted = $payload['quoted'] ?? null;

        // As the Evolution API reports a reply: its `contextInfo` beside the message.
        $contextInfo = array_filter([
            'stanzaId' => $quoted['key']['id'] ?? null,
            'participant' => $quoted !== null ? ($quoted['sender_jid'] ?? null) : null,
            'quotedMessage' => $quoted !== null ? self::quotedContent($quoted['type'] ?? null, $quoted['body'] ?? null) : null,
            'mentionedJid' => $payload['mentioned'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $recordMessages($delivery->account, [array_filter([
            'key' => ['remoteJid' => $delivery->target->jid, 'fromMe' => true, 'id' => $messageId],
            'messageType' => $type,
            'message' => $content,
            'contextInfo' => $contextInfo !== [] ? $contextInfo : null,
            'messageTimestamp' => now()->getTimestamp(),
            'status' => 'SERVER_ACK',
        ], fn (mixed $value): bool => $value !== null)], live: true);

        if ($delivery->media_path !== null) {
            $delivery->target->chatMessages()
                ->where('external_id', $messageId)
                ->whereNull('media_path')
                ->update(['media_path' => $delivery->media_path]);
        }
    }

    /**
     * What a quoted message said, as WhatsApp copies it into a reply.
     *
     * @return array<string, mixed>
     */
    private static function quotedContent(?string $type, ?string $body): array
    {
        return in_array($type, [null, 'conversation', 'extendedTextMessage'], true)
            ? ['conversation' => (string) $body]
            : [$type => array_filter(['caption' => $body], fn (?string $caption): bool => $caption !== null)];
    }

    /**
     * The message type and content of what the delivery sent, as WhatsApp
     * would report it.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function contentOf(WhatsappDelivery $delivery): array
    {
        $payload = $delivery->payload ?? [];

        if (isset($payload['poll'])) {
            $type = $payload['poll']['selectable_count'] === 1 ? 'pollCreationMessageV3' : 'pollCreationMessage';

            return [$type, [$type => [
                'name' => $payload['poll']['name'],
                'options' => array_map(fn (string $option): array => ['optionName' => $option], $payload['poll']['options']),
                'selectableOptionsCount' => $payload['poll']['selectable_count'],
            ]]];
        }

        if (isset($payload['location'])) {
            return ['locationMessage', ['locationMessage' => array_filter([
                'degreesLatitude' => $payload['location']['latitude'],
                'degreesLongitude' => $payload['location']['longitude'],
                'name' => $payload['location']['name'] ?? null,
                'address' => $payload['location']['address'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '')]];
        }

        if (isset($payload['contacts'])) {
            $cards = array_map(function (array $contact): array {
                $digits = (string) preg_replace('/\D/', '', $contact['number']);

                return ['displayName' => $contact['name'], 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:{$contact['name']}\nTEL;type=CELL;waid={$digits}:+{$digits}\nEND:VCARD"];
            }, $payload['contacts']);

            return count($cards) === 1
                ? ['contactMessage', ['contactMessage' => $cards[0]]]
                : ['contactsArrayMessage', ['contactsArrayMessage' => ['displayName' => trans_choice(':count contact|:count contacts', count($cards)), 'contacts' => $cards]]];
        }

        if ($delivery->media_path !== null) {
            $type = ($payload['sticker'] ?? false) === true ? 'stickerMessage' : self::mediaTypeFor((string) $delivery->media_mime).'Message';

            return [$type, [$type => array_filter(['caption' => $delivery->body, 'mimetype' => $delivery->media_mime, 'fileName' => $delivery->media_name], filled(...))]];
        }

        return ['conversation', ['conversation' => $delivery->body]];
    }

    /**
     * The last attempt failed, or the account was not connected.
     */
    public function failed(?Throwable $exception): void
    {
        app(WhatsappDeliveryFailure::class)->record(
            $this->delivery->refresh(),
            $exception?->getMessage() ?? __('The WhatsApp message could not be sent.'),
        );
    }
}
