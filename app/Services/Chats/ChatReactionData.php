<?php

namespace App\Services\Chats;

/**
 * One emoji under a message, with how many reacted with it, whether the
 * user is among them and who they are.
 */
final readonly class ChatReactionData
{
    /**
     * @param  list<string>  $names
     */
    public function __construct(
        public string $emoji,
        public int $count,
        public bool $mine,
        public array $names = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emoji' => $this->emoji,
            'count' => $this->count,
            'mine' => $this->mine,
            'names' => $this->names,
        ];
    }
}
