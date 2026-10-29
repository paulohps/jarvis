<?php

namespace App\Http\Resources;

use App\Models\WhatsappChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * A message of a chat of the WhatsApp screen of the apps: who sent it (in
 * a group), its text (each `@number` it mentions as `@name`), the message
 * it answers (`quoted`, with its `friendly_id` when kept), its ticks
 * (`receipt`, for what the number sent), whether it was forwarded, edited,
 * pinned, starred or deleted for everyone, a location, contacts or a poll
 * (with its votes), and its media: kind, what WhatsApp
 * said about the file (size, name, length, waveform, tiny preview) and the
 * URL that hands the file over, fetching it from WhatsApp the first time
 * (null when it is too big to fetch): signed, no token needed, valid for
 * MEDIA_URL_SECONDS, so the apps' players and images load it as is.
 *
 * @mixin WhatsappChatMessage
 */
class WhatsappConversationMessageResource extends JsonResource
{
    public const int MEDIA_URL_SECONDS = 6 * 3600;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappChatMessage $message */
        $message = $this->resource;
        $kind = $message->mediaKind();

        return [
            'friendly_id' => $message->friendly_id,
            'from_me' => $message->from_me,
            'type' => $message->message_type,
            'sender' => $message->from_me ? null : $message->senderLabel(),
            'body' => $message->bodyWithMentions(),
            'quoted' => $message->quoted === null ? null : [
                'friendly_id' => $message->relationLoaded('quotedMessage') ? $message->quotedMessage?->friendly_id : null,
                'sender' => $message->quotedSenderLabel(),
                'from_me' => $message->quotedMessage?->from_me ?? false,
                'type' => $message->quoted['type'] ?? null,
                'body' => $message->quotedMessage?->bodyWithMentions() ?? $message->quoted['body'] ?? null,
            ],
            'mentions' => array_map(fn (string $jid): array => ['jid' => $jid, 'name' => $message->contextNames[$jid] ?? null], $message->mentions ?? []),
            'forwarded' => $message->is_forwarded,
            'edited' => $message->edited_at !== null,
            'can_edit' => $message->canEdit(),
            'can_delete_for_everyone' => $message->canDeleteForEveryone(),
            'sticker' => $message->message_type === 'stickerMessage',
            'pinned' => $message->isPinned(),
            'starred' => $message->starred_at !== null,
            'location' => $message->payload['location'] ?? null,
            'contacts' => $message->payload['contacts'] ?? null,
            'poll' => $message->isPoll() ? [
                'name' => $message->payload['poll']['name'],
                'selectable_count' => $message->payload['poll']['selectable_count'],
                'options' => $message->relationLoaded('pollVotes') ? $message->pollTally() : array_map(fn (string $name): array => ['name' => $name, 'votes' => 0, 'mine' => false], $message->payload['poll']['options']),
            ] : null,
            'receipt' => $message->from_me ? $this->receiptName($message->receipt) : null,
            'revoked' => $message->isRevoked(),
            'media' => $kind === null ? null : [
                'kind' => $kind,
                'mime' => $message->media_mime,
                'name' => $message->media_name,
                'size' => $message->media_size,
                'seconds' => $message->media_seconds,
                'waveform' => $kind === 'audio' ? $message->waveformBars() : null,
                'thumbnail' => $message->media_thumbnail,
                'url' => $message->canDownloadMedia() ? URL::temporarySignedRoute('api.whatsapp.media', now()->addSeconds(self::MEDIA_URL_SECONDS), $message) : null,
            ],
            'reactions' => $message->relationLoaded('reactions') ? $message->reactionSummary() : [],
            'sent_at' => $message->sent_at->toIso8601String(),
        ];
    }

    private function receiptName(?int $receipt): ?string
    {
        return match ($receipt) {
            WhatsappChatMessage::RECEIPT_FAILED => 'failed',
            WhatsappChatMessage::RECEIPT_PENDING => 'pending',
            WhatsappChatMessage::RECEIPT_SENT => 'sent',
            WhatsappChatMessage::RECEIPT_DELIVERED => 'delivered',
            WhatsappChatMessage::RECEIPT_READ => 'read',
            WhatsappChatMessage::RECEIPT_PLAYED => 'played',
            default => null,
        };
    }
}
