<?php

namespace App\Services\Notifications;

/**
 * A notification written out for one recipient on one channel: title and
 * body rendered in their language, the actions with their URLs, and the
 * context the templates were rendered with.
 */
final readonly class RenderedNotification
{
    /**
     * @param  list<array{label: string, url: string}>  $actions
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $body,
        public array $actions,
        public array $context,
    ) {}

    /**
     * The `data` stored by the in-app channel.
     *
     * @return array{key: string, title: string, body: string, actions: list<array{label: string, url: string}>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'body' => $this->body,
            'actions' => $this->actions,
            'context' => $this->context,
        ];
    }
}
