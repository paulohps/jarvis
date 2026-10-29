<?php

namespace App\Http\Resources;

use App\Models\WhatsappTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A contact or group an account sends to (US-7.3): its name to show, its
 * kind, its number in E.164 (null for a group) and whether it was synced
 * or typed.
 *
 * @mixin WhatsappTarget
 */
class WhatsappTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappTarget $target */
        $target = $this->resource;

        return [
            'friendly_id' => $target->friendly_id,
            'name' => $target->displayName(),
            'type' => $target->type->slug,
            'phone_number' => $target->phoneNumber(),
            'is_synced' => $target->is_synced,
        ];
    }
}
