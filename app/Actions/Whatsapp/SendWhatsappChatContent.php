<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends to a chat of the WhatsApp screen what is no plain text: a poll, a
 * location, contact cards or a sticker, through the WhatsApp service
 * (SendWhatsAppMessage, source `chat`, the content in the delivery's
 * `payload`), like SendWhatsappChatMessage does with a text. A poll, a
 * location or a sticker may answer a message of the chat (contacts go
 * without the quote, which the Evolution API does not send with them).
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SendWhatsappChatContent
{
    use AuthorizesWhatsappChat;

    /**
     * The Evolution API's limits for a poll: 2 to 10 options (WhatsApp
     * allows 12).
     */
    public const int MIN_POLL_OPTIONS = 2;

    public const int MAX_POLL_OPTIONS = 10;

    /**
     * How many contact cards go in one message.
     */
    public const int MAX_CONTACTS = 10;

    /**
     * WhatsApp's limit for a sticker file.
     */
    public const int MAX_STICKER_KILOBYTES = 1024;

    public function __construct(private SendWhatsAppMessage $send) {}

    /**
     * A poll: one choice, or as many as each person wants (`$multiple`).
     * `$now` sends it before returning (the chat layer, which hands back the
     * message as recorded) instead of queueing it.
     *
     * @param  list<string>  $options
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function poll(User $actor, WhatsappTarget $target, string $question, array $options, bool $multiple, ?WhatsappChatMessage $replyTo = null, bool $now = false): WhatsappDelivery
    {
        $this->authorizeChat($actor, $target);

        $poll = self::pollOf($question, $options, $multiple);

        return $this->deliver($actor, $target, $poll['name'], ['poll' => $poll], $replyTo, now: $now);
    }

    /**
     * A poll as the delivery's payload takes it (also what a scheduled
     * message keeps, SavesWhatsappMessage): the question and the options
     * trimmed, the blank options dropped. Errors come under `question`,
     * `options` and `options.*`.
     *
     * @param  array<mixed>  $options
     * @return array{name: string, options: list<string>, selectable_count: int}
     *
     * @throws ValidationException
     */
    public static function pollOf(mixed $question, array $options, bool $multiple): array
    {
        $options = array_values(array_filter(array_map(fn (mixed $option): string => is_string($option) ? mb_trim($option) : '', $options), fn (string $option): bool => $option !== ''));

        /** @var array{question: string, options: list<string>} $validated */
        $validated = Validator::make(['question' => is_string($question) ? mb_trim($question) : $question, 'options' => $options], [
            'question' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:'.self::MIN_POLL_OPTIONS, 'max:'.self::MAX_POLL_OPTIONS],
            'options.*' => ['string', 'max:100', 'distinct:ignore_case'],
        ], [], ['options.*' => __('option')])->validate();

        return ['name' => $validated['question'], 'options' => $validated['options'], 'selectable_count' => $multiple ? 0 : 1];
    }

    /**
     * A location, with an optional name and address.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function location(User $actor, WhatsappTarget $target, float $latitude, float $longitude, ?string $name = null, ?string $address = null, ?WhatsappChatMessage $replyTo = null): WhatsappDelivery
    {
        $this->authorizeChat($actor, $target);

        /** @var array{latitude: float, longitude: float, name: string|null, address: string|null} $validated */
        $validated = Validator::make(
            ['latitude' => $latitude, 'longitude' => $longitude, 'name' => filled($name) ? mb_trim((string) $name) : null, 'address' => filled($address) ? mb_trim((string) $address) : null],
            [
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'name' => ['nullable', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:500'],
            ],
        )->validate();

        return $this->deliver($actor, $target, $validated['name'] ?? '', [
            'location' => ['latitude' => (float) $validated['latitude'], 'longitude' => (float) $validated['longitude'], 'name' => $validated['name'], 'address' => $validated['address']],
        ], $replyTo);
    }

    /**
     * Contact cards of people the number knows (contact chats of the same
     * number, by friendly id), each with its name in Jarvis and number.
     *
     * @param  list<string>  $contactIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function contacts(User $actor, WhatsappTarget $target, array $contactIds): WhatsappDelivery
    {
        $this->authorizeChat($actor, $target);

        Validator::make(['contacts' => $contactIds], [
            'contacts' => ['required', 'array', 'min:1', 'max:'.self::MAX_CONTACTS],
            'contacts.*' => ['string', 'distinct'],
        ])->validate();

        $contacts = $target->account->targets()
            ->whereIn('friendly_id', $contactIds)
            ->where('jid', 'like', '%'.WhatsappTarget::CONTACT_JID_SUFFIX)
            ->get();

        if ($contacts->count() !== count($contactIds)) {
            throw ValidationException::withMessages(['contacts' => __('Pick contacts of this number with a phone number.')]);
        }

        $cards = $contacts->map(fn (WhatsappTarget $contact): array => [
            'name' => $contact->displayName(),
            'number' => (string) strstr($contact->jid, '@', true),
        ])->values()->all();

        return $this->deliver($actor, $target, implode(', ', array_column($cards, 'name')), ['contacts' => $cards]);
    }

    /**
     * An image as a sticker: a WebP as it is, a PNG or JPEG made into one
     * by the Evolution API (a still one).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function sticker(User $actor, WhatsappTarget $target, UploadedFile $file, ?WhatsappChatMessage $replyTo = null): WhatsappDelivery
    {
        $this->authorizeChat($actor, $target);

        Validator::make(['sticker' => $file], [
            'sticker' => ['required', 'file', 'mimetypes:image/webp,image/png,image/jpeg', 'max:'.self::MAX_STICKER_KILOBYTES],
        ])->validate();

        $mime = $file->getMimeType() ?? 'image/webp';
        $media = [
            'path' => WhatsappChatMedia::storeOutgoing($target->account->friendly_id, (string) $file->get(), $mime),
            'mime' => $mime,
            'name' => null,
        ];

        return $this->deliver($actor, $target, '', ['sticker' => true], $replyTo, $media);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{path: string, mime: string, name: string|null}|null  $media
     */
    private function deliver(User $actor, WhatsappTarget $target, string $body, array $payload, ?WhatsappChatMessage $replyTo = null, ?array $media = null, bool $now = false): WhatsappDelivery
    {
        if ($replyTo !== null) {
            $payload['quoted'] = SendWhatsappChatMessage::quoteOf($target, $replyTo);
        }

        return ($this->send)($target->account, $target, $body, WhatsappDeliverySource::CHAT, triggeredBy: $actor, media: $media, payload: $payload, now: $now);
    }
}
