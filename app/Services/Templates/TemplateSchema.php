<?php

namespace App\Services\Templates;

use Illuminate\Support\Str;

/**
 * The contract of a template context (a notification type, a WhatsApp
 * message, an invoice e-mail): the variables its templates may use, each with
 * its type and example. Nothing outside the schema can be written in a
 * template: the validator refuses unknown paths when a template is saved,
 * which keeps the writer's wording from reaching arbitrary data.
 *
 * A context only lists its variables; the lookups the validator and the
 * editor need are derived from that list. The variables every context can
 * offer come from TemplateContextBuilder::commonVariables().
 */
abstract class TemplateSchema
{
    /**
     * @var array<string, TemplateVariable>|null
     */
    private ?array $indexed = null;

    /**
     * @return list<TemplateVariable>
     */
    abstract public function variables(): array;

    /**
     * Every variable, keyed by its dotted path.
     *
     * @return array<string, TemplateVariable>
     */
    public function all(): array
    {
        if ($this->indexed === null) {
            $this->indexed = [];

            foreach ($this->variables() as $variable) {
                $this->indexed[$variable->path] = $variable;
            }
        }

        return $this->indexed;
    }

    public function find(string $path): ?TemplateVariable
    {
        return $this->all()[$path] ?? null;
    }

    public function hasScalar(string $path): bool
    {
        $variable = $this->find($path);

        return $variable !== null && ! $variable->isCollection();
    }

    public function hasCollection(string $path): bool
    {
        return $this->collectionAt($path) !== null;
    }

    public function collectionAt(string $path): ?TemplateVariable
    {
        $variable = $this->find($path);

        return $variable?->isCollection() ? $variable : null;
    }

    /**
     * Every value a variable can hold. A loop field is named through its list:
     * `invoice.items.status`.
     *
     * @return list<string> empty when the variable can hold anything
     */
    public function valuesFor(string $path): array
    {
        $variable = $this->find($path);

        if ($variable !== null) {
            return $variable->values;
        }

        foreach ($this->all() as $collectionPath => $collection) {
            if ($collection->isCollection() && str_starts_with($path, $collectionPath.'.')) {
                return $collection->fields[Str::after($path, $collectionPath.'.')]->values ?? [];
            }
        }

        return [];
    }

    /**
     * The first segment of every declared path, so a loop name cannot hide
     * variables the template relies on.
     *
     * @return list<string>
     */
    public function rootSegments(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $path): string => Str::before($path, '.'),
            array_keys($this->all()),
        )));
    }
}
