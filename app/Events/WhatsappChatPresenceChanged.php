<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone in a chat is typing, recording an audio, online or gone
 * (`presence`: composing, recording, available, unavailable or paused):
 * the WhatsApp screen of the chat's owner shows it for a few seconds.
 * Nothing is kept; broadcast at once, on the owner's private channel
 * only. `who` names the participant in a group (null in a one-to-one
 * chat), `last_seen` is when a contact was last online, when WhatsApp
 * shares it.
 */
final class WhatsappChatPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $accountId,
        public readonly string $chatId,
        public readonly string $presence,
        public readonly ?string $who = null,
        public readonly ?string $lastSeen = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(NotificationCreated::channelFor($this->userId))];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp.chat.presence';
    }

    /**
     * @return array{account_id: string, chat_id: string, presence: string, who: string|null, last_seen: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'chat_id' => $this->chatId,
            'presence' => $this->presence,
            'who' => $this->who,
            'last_seen' => $this->lastSeen,
        ];
    }
}
