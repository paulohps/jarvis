<?php

namespace App\Http\Resources;

use App\Models\ChatTag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's tags for the chat screen: its name, and its color as
 * `#rrggbb` (`color`) and by name (`color_name`, what saving it takes).
 *
 * @mixin ChatTag
 */
class ChatTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatTag $tag */
        $tag = $this->resource;

        return [
            'friendly_id' => $tag->friendly_id,
            'name' => $tag->name,
            'color' => $tag->hex(),
            'color_name' => $tag->color,
        ];
    }
}
