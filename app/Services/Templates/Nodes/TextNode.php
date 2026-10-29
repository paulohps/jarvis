<?php

namespace App\Services\Templates\Nodes;

/**
 * Literal text, written as it was typed.
 */
final readonly class TextNode implements Node
{
    public function __construct(public string $text) {}
}
