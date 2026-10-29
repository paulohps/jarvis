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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sends what was typed in a chat of the WhatsApp screen, as it is (no
 * template), and the file attached, if any (up to MAX_FILE_KILOBYTES, the
 * text then its caption), through the WhatsApp service
 * (SendWhatsAppMessage, source `chat`): logged in the history, with its
 * retries and failure notice. Once sent, it shows in the chat
 * (DeliverWhatsappMessage). The file waits on the private disk
 * (WhatsappChatMedia) and stays as the chat's copy.
 *
 * It may answer a message of the chat (`$replyTo`, quoted as WhatsApp does;
 * a voice note goes without the quote, which the Evolution API does not
 * send with one). In a group, each `@number` typed mentions that member
 * (mentionsIn()).
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SendWhatsappChatMessage
{
    use AuthorizesWhatsappModule;

    public const int MAX_LENGTH = 4096;

    /**
     * Livewire's limit for an upload (12 MB), under WhatsApp's for videos
     * (16 MB).
     */
    public const int MAX_FILE_KILOBYTES = 12 * 1024;

    public function __construct(private SendWhatsAppMessage $send) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappTarget $target, string $text, ?UploadedFile $file = null, ?WhatsappChatMessage $replyTo = null, bool $now = false): WhatsappDelivery
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $target->account);

        /** @var array{text: string|null} $validated */
        $validated = Validator::make(
            ['text' => mb_trim($text) !== '' ? mb_trim($text) : null, 'file' => $file],
            [
                'text' => ['required_without:file', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
                'file' => ['nullable', 'file', 'max:'.self::MAX_FILE_KILOBYTES],
            ],
        )->validate();

        $media = null;

        if ($file !== null) {
            $mime = $file->getMimeType() ?? 'application/octet-stream';
            $media = [
                'path' => WhatsappChatMedia::storeOutgoing($target->account->friendly_id, (string) $file->get(), $mime),
                'mime' => $mime,
                'name' => mb_substr($file->getClientOriginalName(), 0, 255) ?: null,
            ];
        }

        $payload = array_filter([
            'quoted' => $replyTo !== null ? self::quoteOf($target, $replyTo) : null,
            'mentioned' => $target->isGroup() ? self::mentionsIn($validated['text'] ?? '') : [],
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        return ($this->send)($target->account, $target, $validated['text'] ?? '', WhatsappDeliverySource::CHAT, triggeredBy: $actor, media: $media, payload: $payload, now: $now);
    }

    /**
     * The delivery's `quoted`: the message's key (and text) to send, plus
     * what Jarvis shows of it in the reply.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException when the message is not of the chat
     */
    public static function quoteOf(WhatsappTarget $target, WhatsappChatMessage $replyTo): array
    {
        if ($replyTo->whatsapp_target_id !== $target->id) {
            throw ValidationException::withMessages(['reply_to' => __('The message answered is not of this chat.')]);
        }

        $replyTo->setRelation('target', $target);

        return [
            ...$replyTo->quoteForSending(),
            'sender_jid' => $replyTo->from_me ? null : ($replyTo->sender_jid ?? $target->jid),
            'type' => $replyTo->message_type,
            'body' => $replyTo->body !== null ? mb_substr($replyTo->body, 0, 500) : null,
        ];
    }

    /**
     * The members a text mentions: each `@` followed by a number with its
     * country code (8 to 15 digits), as the WhatsApp apps write them.
     *
     * @return list<string> phone JIDs
     */
    public static function mentionsIn(string $text): array
    {
        preg_match_all('/(?<![\w@])@(\d{8,15})(?!\d)/', $text, $matches);

        return array_values(array_unique(array_map(fn (string $digits): string => $digits.WhatsappTarget::CONTACT_JID_SUFFIX, $matches[1])));
    }
}
