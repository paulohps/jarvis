<?php

namespace App\Services\Chats;

use Carbon\CarbonImmutable;

/**
 * An account a user connected on a provider (a Slack workspace, a WhatsApp
 * number), as the channels screen lists them: usable or not, and why not.
 */
final readonly class ChatAccountData
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $label,
        public ?string $detail,
        public bool $isUsable,
        public ?string $error,
        public ?CarbonImmutable $connectedAt,
        public ?CarbonImmutable $syncedAt,
        public ?string $url = null,
        public int $conversationCount = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'detail' => $this->detail,
            'is_usable' => $this->isUsable,
            'error' => $this->error,
            'connected_at' => $this->connectedAt?->toIso8601String(),
            'synced_at' => $this->syncedAt?->toIso8601String(),
            'url' => $this->url,
            'conversation_count' => $this->conversationCount,
        ];
    }
}
