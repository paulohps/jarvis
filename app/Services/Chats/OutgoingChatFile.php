<?php

namespace App\Services\Chats;

/**
 * A file to send with a message: where it is on disk right now, and the
 * name and type it goes out with.
 */
final readonly class OutgoingChatFile
{
    public function __construct(
        public string $path,
        public string $name,
        public ?string $mime = null,
    ) {}

    public function size(): int
    {
        return (int) filesize($this->path);
    }
}
