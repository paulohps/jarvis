<?php

namespace App\Services\Templates;

/**
 * A piece of a template: literal text or the inside of a `{% %}` tag.
 */
final readonly class Token
{
    /**
     * @param  int  $offset  where the piece starts, in characters from 0
     */
    private function __construct(
        public bool $isTag,
        public string $value,
        public int $offset,
    ) {}

    public static function text(string $value, int $offset): self
    {
        return new self(false, $value, $offset);
    }

    public static function tag(string $value, int $offset): self
    {
        return new self(true, $value, $offset);
    }
}
