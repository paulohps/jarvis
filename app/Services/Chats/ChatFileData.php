<?php

namespace App\Services\Chats;

/**
 * A file attached to a message of any provider, as the screens show it:
 * `kind` is `image`, `video`, `audio` or `document`; `url` is where Jarvis
 * serves it from (signed in or by a signed link), `thumbnailUrl` a small
 * preview when there is one.
 */
final readonly class ChatFileData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mime,
        public ?int $size,
        public string $kind,
        public ?string $url,
        public ?string $thumbnailUrl = null,
    ) {}

    /**
     * The kind of a MIME type.
     */
    public static function kindOf(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'video/') => 'video',
            str_starts_with((string) $mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'kind' => $this->kind,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
