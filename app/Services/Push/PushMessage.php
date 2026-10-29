<?php

namespace App\Services\Push;

/**
 * What a push says: the title and the body as plain text, and the screen
 * tapping it opens (`deep_link` in the data payload), when there is one.
 * Pushes of the same `thread` (a WhatsApp chat) group together on iOS
 * (`thread-id`) and, on Android, the latest replaces the one before
 * (`tag`); `androidChannel` is the Android notification channel the app
 * created for them (FCM's default otherwise).
 */
final readonly class PushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $deepLink = null,
        public ?string $thread = null,
        public ?string $androidChannel = null,
    ) {}

    /**
     * The `data` of the FCM message: string values only, as FCM requires.
     *
     * @return array<string, string>
     */
    public function data(): array
    {
        return array_filter([
            'deep_link' => $this->deepLink,
            'thread' => $this->thread,
        ], fn (?string $value): bool => $value !== null);
    }
}
