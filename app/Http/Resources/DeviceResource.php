<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A device of the user (US-3.4): name, platform and last use (the later of
 * the device's own mark and its token's last request), whether it is
 * registered for push and whether it is the one making the call. The push
 * token itself is never sent back.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Device $device */
        $device = $this->resource;

        $currentToken = $request->user()?->currentAccessToken();

        return [
            'friendly_id' => $device->friendly_id,
            'name' => $device->name,
            'platform' => $device->platform->slug,
            'has_push_token' => $device->push_token !== null,
            'is_current' => $currentToken instanceof PersonalAccessToken
                && $device->personal_access_token_id === $currentToken->getKey(),
            'last_used_at' => $device->lastUsedAt()?->toIso8601String(),
            'created_at' => $device->created_at?->toIso8601String(),
        ];
    }
}
