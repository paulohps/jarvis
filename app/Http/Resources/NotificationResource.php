<?php

namespace App\Http\Resources;

use App\Services\Notifications\NotificationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An in-app notification as the apps show it (US-5.1): the text already in
 * the reader's language, the body sanitized, and only the actions that point
 * inside the application (`NotificationFeed`).
 *
 * @mixin NotificationItem
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var NotificationItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'title' => $item->title,
            'body' => $item->body,
            'url' => $item->url(),
            'actions' => $item->actions,
            'is_read' => $item->isRead,
            'created_at' => $item->createdAt->toIso8601String(),
        ];
    }
}
