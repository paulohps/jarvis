<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A conversation of the chat screen changed — a message came in or went
 * out, was edited, deleted or reacted to, or the conversation itself was
 * renamed or synced: the screen of its owner reloads the list and, when
 * it is the open one, the messages. Broadcast only on the owner's private
 * channel, from the `chats` queue.
 */
final class ChatConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public const string QUEUE = 'chats';

    public function __construct(
        public readonly int $userId,
        public readonly string $conversationId,
        public readonly string $provider,
        public readonly ?string $messageId = null,
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
        return 'chat.conversation.updated';
    }

    /**
     * @return array{conversation_id: string, provider: string, message_id: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'provider' => $this->provider,
            'message_id' => $this->messageId,
        ];
    }

    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }
}
