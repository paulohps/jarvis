<?php

namespace App\Services\Templates;

/**
 * A problem found in a template: an English source text (also its JSON
 * translation key) with its replacements, and where it happened.
 *
 * The template engine never translates, so it runs without the framework;
 * whoever shows the problem to a person calls `translated()`, in the reader's
 * language. Once `locate()`d against its template, the problem also knows its
 * line and column, which is how people find it in a textarea.
 */
final readonly class TemplateError
{
    /**
     * @param  string  $message  English source text with `:placeholders`
     * @param  array<string, string|int>  $replace
     * @param  int  $offset  where the problem starts, in characters from 0
     * @param  int|null  $line  from 1, null until located
     * @param  int|null  $column  from 1, null until located
     */
    public function __construct(
        public string $message,
        public array $replace,
        public int $offset,
        public ?int $line = null,
        public ?int $column = null,
    ) {}

    /**
     * The same problem with the line and column its offset falls on in
     * `$template`.
     */
    public function locate(string $template): self
    {
        $before = mb_substr($template, 0, $this->offset);
        $lastBreak = mb_strrpos($before, "\n");

        return new self(
            $this->message,
            $this->replace,
            $this->offset,
            mb_substr_count($before, "\n") + 1,
            $lastBreak === false ? $this->offset + 1 : $this->offset - $lastBreak,
        );
    }

    /**
     * The character the problem starts at, counting from 1 as people do.
     */
    public function position(): int
    {
        return $this->offset + 1;
    }

    /**
     * The problem in English, with its position. Used by logs and exception
     * messages.
     */
    public function describe(): string
    {
        $problem = strtr($this->message, $this->placeholders());

        if ($this->line !== null && $this->column !== null) {
            return 'Line '.$this->line.', column '.$this->column.': '.$problem;
        }

        return 'At character '.$this->position().': '.$problem;
    }

    /**
     * The problem in the current reader's language, with its position.
     */
    public function translated(): string
    {
        $problem = __($this->message, $this->replace);

        if ($this->line !== null && $this->column !== null) {
            return __('Line :line, column :column: :problem', [
                'line' => $this->line,
                'column' => $this->column,
                'problem' => $problem,
            ]);
        }

        return __('At character :position: :problem', [
            'position' => $this->position(),
            'problem' => $problem,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        $placeholders = [];

        foreach ($this->replace as $key => $value) {
            $placeholders[':'.$key] = (string) $value;
        }

        return $placeholders;
    }
}
