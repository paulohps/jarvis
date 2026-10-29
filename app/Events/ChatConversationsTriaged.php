<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The AI answered a triage of the owner's conversations
 * (TriageChatConversations), or gave up (`failed`): the chat screen of the
 * owner reloads its list. Broadcast only on the owner's private channel.
 */
final class ChatConversationsTriaged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
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
        return 'chat.conversations.triaged';
    }

    /**
     * @return array{failed: bool}
     */
    public function broadcastWith(): array
    {
        return ['failed' => $this->failed];
    }

    public function broadcastQueue(): string
    {
        return ChatConversationUpdated::QUEUE;
    }
}
