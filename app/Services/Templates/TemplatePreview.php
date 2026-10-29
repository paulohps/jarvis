<?php

namespace App\Services\Templates;

/**
 * A template rendered with the sample data of its schema (US-4.2), or the
 * problems that keep it from rendering, already in the reader's language.
 */
final readonly class TemplatePreview
{
    /**
     * @param  string|null  $output  sanitized HTML or plain text, per `$format`; null when the template is invalid
     * @param  list<string>  $errors  translated, each with its line and column
     */
    public function __construct(
        public ?string $output,
        public TemplateFormat $format,
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isHtml(): bool
    {
        return $this->format === TemplateFormat::Html;
    }
}
