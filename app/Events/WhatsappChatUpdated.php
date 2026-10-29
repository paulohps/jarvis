<?php

namespace App\Events;

use App\Jobs\SyncWhatsappTargets;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat got a message, received or sent from any device: the WhatsApp
 * screen of its owner reloads the chat list and, when it is open, the
 * chat. Broadcast only on the owner's private channel.
 */
final class WhatsappChatUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $accountId,
        public readonly string $chatId,
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
        return 'whatsapp.chat.updated';
    }

    /**
     * @return array{account_id: string, chat_id: string}
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'chat_id' => $this->chatId,
        ];
    }

    public function broadcastQueue(): string
    {
        return SyncWhatsappTargets::QUEUE;
    }
}
