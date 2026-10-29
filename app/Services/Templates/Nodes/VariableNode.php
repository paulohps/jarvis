<?php

namespace App\Services\Templates\Nodes;

/**
 * `{% payer.name %}`: the escaped value of a variable.
 */
final readonly class VariableNode implements Node
{
    /**
     * @param  int  $offset  where the tag starts, in characters from 0
     */
    public function __construct(
        public string $path,
        public int $offset,
    ) {}
}
