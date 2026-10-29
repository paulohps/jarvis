<?php

namespace App\Services\Templates\Nodes;

/**
 * `{% for item in invoice.items %}…{% endfor %}`: the body once per item,
 * with the item under the alias.
 */
final readonly class ForNode implements Node
{
    /**
     * @param  list<Node>  $body
     * @param  int  $offset  where the opening tag starts, in characters from 0
     */
    public function __construct(
        public string $alias,
        public string $path,
        public array $body,
        public int $offset,
    ) {}
}
