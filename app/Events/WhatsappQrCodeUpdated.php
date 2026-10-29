<?php

namespace App\Events;

use App\Jobs\SyncWhatsappTargets;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The Evolution API handed out a new QR code for an account still being
 * connected (US-7.1): the connection screen swaps the image without a
 * refresh. Broadcast only on the owner's private channel, since the QR code
 * links a number to the instance.
 */
final class WhatsappQrCodeUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $userId,
        public readonly string $accountId,
        public readonly string $qrCode,
        public readonly ?string $pairingCode = null,
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
        return 'whatsapp.qr-code.updated';
    }

    /**
     * @return array{account_id: string, qr_code: string, pairing_code: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'account_id' => $this->accountId,
            'qr_code' => $this->qrCode,
            // Events queued before `pairingCode` existed unserialize without it.
            'pairing_code' => $this->pairingCode ?? null,
        ];
    }

    public function broadcastQueue(): string
    {
        return SyncWhatsappTargets::QUEUE;
    }
}
