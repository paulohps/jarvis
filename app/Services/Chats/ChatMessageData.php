<?php

namespace App\Services\Chats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A message of a conversation of any provider, as the chat screen and the
 * API show it. `id` is the provider's public id of the message (the
 * friendly id of its row); `threadId` is the id of the message it answers
 * in a thread, `replyCount` how many answer it. `html` is the text
 * rendered with the provider's markup (safe to print), `text` the plain
 * text for previews and the AI. `kind` is `text`, `file` (files with or
 * without a text) or `system` (someone joined, the topic changed…).
 */
final readonly class ChatMessageData
{
    /**
     * @param  list<ChatFileData>  $files
     * @param  list<ChatReactionData>  $reactions
     */
    public function __construct(
        public string $id,
        public string $conversationId,
        public bool $fromMe,
        public ?string $senderId,
        public ?string $senderName,
        public ?string $senderPictureUrl,
        public string $kind,
        public ?string $text,
        public string $html,
        public array $files,
        public ?string $threadId,
        public int $replyCount,
        public array $reactions,
        public CarbonImmutable $sentAt,
        public ?CarbonImmutable $editedAt = null,
        public ?CarbonImmutable $deletedAt = null,
        public bool $canEdit = false,
        public bool $canDelete = false,
        public ?string $externalUrl = null,
    ) {}

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function isReply(): bool
    {
        return $this->threadId !== null && $this->threadId !== $this->id;
    }

    /**
     * What the list shows of it: the text, or what it holds.
     */
    public function preview(): string
    {
        if ($this->isDeleted()) {
            return __('Message deleted');
        }

        if (filled($this->text)) {
            return Str::limit(Str::squish((string) $this->text), 120);
        }

        $file = $this->files[0] ?? null;

        if ($file !== null) {
            return match ($file->kind) {
                'image' => '📷 '.__('Photo'),
                'video' => '🎥 '.__('Video'),
                'audio' => '🎤 '.__('Audio'),
                default => '📄 '.$file->name,
            };
        }

        return __('Message');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversationId,
            'from_me' => $this->fromMe,
            'sender' => $this->senderId === null && $this->senderName === null ? null : [
                'id' => $this->senderId,
                'name' => $this->senderName,
                'picture_url' => $this->senderPictureUrl,
            ],
            'kind' => $this->kind,
            'text' => $this->text,
            'html' => $this->html,
            'files' => array_map(fn (ChatFileData $file): array => $file->toArray(), $this->files),
            'thread_id' => $this->threadId,
            'reply_count' => $this->replyCount,
            'reactions' => array_map(fn (ChatReactionData $reaction): array => $reaction->toArray(), $this->reactions),
            'sent_at' => $this->sentAt->toIso8601String(),
            'edited_at' => $this->editedAt?->toIso8601String(),
            'deleted_at' => $this->deletedAt?->toIso8601String(),
            'can_edit' => $this->canEdit,
            'can_delete' => $this->canDelete,
            'external_url' => $this->externalUrl,
        ];
    }
}
