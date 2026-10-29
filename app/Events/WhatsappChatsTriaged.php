<?php

namespace App\Events;

use App\Jobs\SyncWhatsappTargets;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The AI answered a triage of the owner's chats (TriageWhatsappChats), or
 * gave up (`failed`): the WhatsApp screen of the owner reloads the chat
 * list. Broadcast only on the owner's private channel.
 */
final class WhatsappChatsTriaged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $accountId,
        public readonly bool $failed = false,
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
        return 'whatsapp.chats.triaged';
    }

    /**
     * @return array{account_id: string, failed: bool}
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'failed' => $this->failed,
        ];
    }

    public function broadcastQueue(): string
    {
        return SyncWhatsappTargets::QUEUE;
    }
}
