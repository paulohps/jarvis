<?php

namespace App\Events;

use App\Jobs\SyncWhatsappTargets;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An account's contacts and groups were synced (SyncWhatsappTargets): the
 * account list of its owner shows the new "last synced" without a refresh.
 * Broadcast only on the owner's private channel.
 */
final class WhatsappTargetsSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
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
        return 'whatsapp.account.synced';
    }

    /**
     * @return array{account_id: string}
     */
    public function broadcastWith(): array
    {
        return ['account_id' => $this->accountId];
    }

    public function broadcastQueue(): string
    {
        return SyncWhatsappTargets::QUEUE;
    }
}
