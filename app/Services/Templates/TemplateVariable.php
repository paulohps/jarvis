<?php

namespace App\Services\Templates;

use InvalidArgumentException;

/**
 * A variable a template context exposes: its dotted path, what it holds, a
 * description (English source text, translated where it is shown) and the
 * example value the preview renders with.
 *
 * A collection lists the fields of its items, each a variable named by the
 * field alone (`number`, not `invoices.number`), and its example is a list of
 * items. `values` lists every value a variable can hold, so a condition that
 * compares it with anything else is refused.
 */
final readonly class TemplateVariable
{
    /**
     * @param  array<string, TemplateVariable>  $fields  field name => field, only for collections
     * @param  list<string>  $values  empty when the variable can hold anything
     */
    public function __construct(
        public string $path,
        public TemplateVariableType $type,
        public string $description,
        public mixed $example = null,
        public array $fields = [],
        public array $values = [],
    ) {
        if ($fields !== [] && $type !== TemplateVariableType::Collection) {
            throw new InvalidArgumentException(sprintf('Only a collection has fields, and "%s" is not one.', $path));
        }
    }

    /**
     * @param  list<string>  $values
     */
    public static function text(string $path, string $description, ?string $example = null, array $values = []): self
    {
        return new self($path, TemplateVariableType::Text, $description, $example, values: $values);
    }

    public static function number(string $path, string $description, int|float|null $example = null): self
    {
        return new self($path, TemplateVariableType::Number, $description, $example);
    }

    /**
     * @param  string|null  $example  a `Y-m-d` day
     */
    public static function date(string $path, string $description, ?string $example = null): self
    {
        return new self($path, TemplateVariableType::Date, $description, $example);
    }

    /**
     * @param  string|null  $example  an instant in UTC, such as `2026-10-01 14:30`
     */
    public static function dateTime(string $path, string $description, ?string $example = null): self
    {
        return new self($path, TemplateVariableType::DateTime, $description, $example);
    }

    public static function money(string $path, string $description, string|int|float|null $example = null): self
    {
        return new self($path, TemplateVariableType::Money, $description, $example);
    }

    public static function boolean(string $path, string $description, ?bool $example = null): self
    {
        return new self($path, TemplateVariableType::Boolean, $description, $example);
    }

    /**
     * @param  list<TemplateVariable>  $fields  each named by the field alone
     * @param  list<array<string, mixed>>  $example  items, field name => raw value
     */
    public static function collection(string $path, string $description, array $fields, array $example = []): self
    {
        $keyed = [];

        foreach ($fields as $field) {
            $keyed[$field->path] = $field;
        }

        return new self($path, TemplateVariableType::Collection, $description, $example, $keyed);
    }

    public function isCollection(): bool
    {
        return $this->type === TemplateVariableType::Collection;
    }
}
