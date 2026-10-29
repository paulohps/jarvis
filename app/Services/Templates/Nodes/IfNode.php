<?php

namespace App\Services\Templates\Nodes;

/**
 * `{% if … %}…{% else %}…{% endif %}`: either a variable that must have a
 * value, or a variable compared with a quoted value (`==` or `!=`).
 */
final readonly class IfNode implements Node
{
    public const string EQUALS = '==';

    public const string NOT_EQUALS = '!=';

    /**
     * @param  list<Node>  $then
     * @param  list<Node>  $else
     * @param  int  $offset  where the opening tag starts, in characters from 0
     * @param  self::EQUALS|self::NOT_EQUALS|null  $operator  null only checks that the variable has a value
     */
    public function __construct(
        public string $path,
        public array $then,
        public array $else,
        public int $offset,
        public ?string $operator = null,
        public ?string $value = null,
    ) {}

    public function isComparison(): bool
    {
        return $this->operator !== null;
    }

    /**
     * Whether `$candidate` is the value this condition names. Case and
     * surrounding spaces do not count, so "brl" typed by hand still matches
     * "BRL".
     */
    public function names(string $candidate): bool
    {
        return mb_strtolower(mb_trim($candidate)) === mb_strtolower(mb_trim((string) $this->value));
    }
}
