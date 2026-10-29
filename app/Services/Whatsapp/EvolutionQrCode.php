<?php

namespace App\Services\Whatsapp;

/**
 * A QR code to link a number, as the Evolution API hands it out: the image
 * (`data:image/png;base64,...`), the raw code it encodes and the pairing
 * code for linking by phone number.
 */
final readonly class EvolutionQrCode
{
    public function __construct(
        public ?string $base64,
        public ?string $code = null,
        public ?string $pairingCode = null,
    ) {}

    /**
     * The QR code of an Evolution API payload (`{base64, code,
     * pairingCode}`), or null when it carries no image.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): ?self
    {
        $base64 = $payload['base64'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        return new self(
            $base64,
            is_string($payload['code'] ?? null) ? $payload['code'] : null,
            is_string($payload['pairingCode'] ?? null) ? $payload['pairingCode'] : null,
        );
    }
}
