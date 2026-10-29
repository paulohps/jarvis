<?php

namespace App\Services\Notifications;

use Carbon\CarbonInterface;

/**
 * An in-app notification as the bell and the history show it: the stored
 * text (the body sanitized again on the way out) and its actions.
 */
final readonly class NotificationItem
{
    /**
     * @param  list<array{label: string, url: string}>  $actions
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public array $actions,
        public bool $isRead,
        public CarbonInterface $createdAt,
    ) {}

    /**
     * Where clicking the notification leads: its first action.
     */
    public function url(): ?string
    {
        return $this->actions[0]['url'] ?? null;
    }
}
