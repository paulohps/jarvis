<?php

namespace App\Services\Templates;

use RuntimeException;

/**
 * A template that cannot be parsed. The validator turns it into a
 * TemplateError, so a broken template is refused when it is saved; reaching
 * the renderer means a stored template skipped that check.
 */
final class TemplateSyntaxException extends RuntimeException
{
    public function __construct(public readonly TemplateError $error)
    {
        parent::__construct($error->describe());
    }

    /**
     * @param  string  $message  English source text with `:placeholders`
     * @param  array<string, string|int>  $replace
     */
    public static function at(int $offset, string $message, array $replace = []): self
    {
        return new self(new TemplateError($message, $replace, $offset));
    }
}
