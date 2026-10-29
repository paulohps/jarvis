<?php

namespace App\Services\Chats;

/**
 * The bytes of a file a driver fetched from its provider, with the name
 * and type to serve it under.
 */
final readonly class ChatFileContents
{
    public function __construct(
        public string $contents,
        public string $name,
        public string $mime,
    ) {}
}
