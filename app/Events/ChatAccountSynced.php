<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account of a chat provider finished syncing its conversations (or
 * stopped working): the channels screen and the chat screen of its owner
 * reload. Broadcast only on the owner's private channel.
 */
final class ChatAccountSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $provider,
        public readonly string $accountId,
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
        return 'chat.account.synced';
    }

    /**
     * @return array{provider: string, account_id: string}
     */
    public function broadcastWith(): array
    {
        return ['provider' => $this->provider, 'account_id' => $this->accountId];
    }

    public function broadcastQueue(): string
    {
        return ChatConversationUpdated::QUEUE;
    }
}
