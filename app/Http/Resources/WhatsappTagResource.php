<?php

namespace App\Http\Resources;

use App\Models\WhatsappTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's own tags for WhatsApp chats: its name, and its color
 * as `#rrggbb` (`color`) and by name (`color_name`, a key of
 * WhatsappTarget::PRIORITY_COLORS, what saving it takes).
 *
 * @mixin WhatsappTag
 */
class WhatsappTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WhatsappTag $tag */
        $tag = $this->resource;

        return [
            'friendly_id' => $tag->friendly_id,
            'name' => $tag->name,
            'color' => $tag->hex(),
            'color_name' => $tag->color,
        ];
    }
}
