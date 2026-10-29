<?php

namespace App\Events;

use App\Jobs\SyncWhatsappTargets;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account went `connecting`, `open` or `closed` (US-7.2): the account
 * list and the connection screen of its owner show the new status without a
 * refresh. Broadcast only on the owner's private channel.
 */
final class WhatsappAccountStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $accountId,
        public readonly string $status,
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
        return 'whatsapp.account.status-changed';
    }

    /**
     * @return array{account_id: string, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'status' => $this->status,
        ];
    }

    public function broadcastQueue(): string
    {
        return SyncWhatsappTargets::QUEUE;
    }
}
