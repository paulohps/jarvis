<?php

namespace App\Services\Chats;

/**
 * What the user sends to a conversation: a text and/or files, in the
 * thread of a message when `threadId` is given. The text is plain, with
 * `@` mentions as the composer writes them (`@handle`); the driver turns
 * them into the provider's own. A `poll` (`name`, `options`,
 * `selectable_count`: 1 for one answer, 0 for any) goes instead of the
 * text and the files, where the provider has polls (ChatCapabilities).
 *
 * @param  list<OutgoingChatFile>  $files
 */
final readonly class OutgoingChatMessage
{
    /**
     * @param  list<OutgoingChatFile>  $files
     * @param  array{name: string, options: list<string>, selectable_count: int}|null  $poll
     */
    public function __construct(
        public string $text = '',
        public array $files = [],
        public ?string $threadId = null,
        public ?array $poll = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '' && $this->files === [] && $this->poll === null;
    }
}
