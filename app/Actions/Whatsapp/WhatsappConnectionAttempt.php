<?php

namespace App\Actions\Whatsapp;

use App\Models\WhatsappAccount;
use App\Services\Whatsapp\EvolutionQrCode;

/**
 * An account waiting for its QR code to be scanned, with the QR code to
 * show (null when the Evolution API did not hand one out yet; the webhook
 * brings it).
 */
final readonly class WhatsappConnectionAttempt
{
    public function __construct(
        public WhatsappAccount $account,
        public ?EvolutionQrCode $qrCode,
    ) {}
}
