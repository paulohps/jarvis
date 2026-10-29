<?php

namespace App\Services\Chats;

/**
 * Someone in a conversation, for the `@` mentions of the composer and the
 * names beside the messages: `id` is the provider's id of the person,
 * `handle` what the user types after `@`.
 */
final readonly class ChatParticipantData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $handle = null,
        public ?string $pictureUrl = null,
        public bool $isMe = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'picture_url' => $this->pictureUrl,
            'is_me' => $this->isMe,
        ];
    }
}
