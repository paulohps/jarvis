<?php

namespace App\Services\Templates;

use App\Services\Templates\Nodes\ForNode;
use App\Services\Templates\Nodes\IfNode;
use App\Services\Templates\Nodes\Node;
use App\Services\Templates\Nodes\VariableNode;
use Illuminate\Support\Str;

/**
 * Checks a template against the variables its context declares (ported from
 * my-finance), when the template is saved: a template that would break or
 * silently render nothing is refused while someone is still looking at it.
 * Every problem says where it starts, with its line and column.
 */
final readonly class TemplateValidator
{
    public function __construct(private TemplateParser $parser = new TemplateParser) {}

    /**
     * @return list<TemplateError> empty when the template is valid
     */
    public function validate(string $template, TemplateSchema $schema): array
    {
        try {
            $nodes = $this->parser->parse($template);
        } catch (TemplateSyntaxException $exception) {
            return [$exception->error];
        }

        return array_map(
            static fn (TemplateError $error): TemplateError => $error->locate($template),
            $this->walk($nodes, $schema, [], []),
        );
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, TemplateVariable>  $aliases  loop names in scope
     * @param  array<string, string>  $loopPaths  loop name => the list it walks
     * @return list<TemplateError>
     */
    private function walk(array $nodes, TemplateSchema $schema, array $aliases, array $loopPaths): array
    {
        $errors = [];

        foreach ($nodes as $node) {
            if ($node instanceof VariableNode) {
                $errors = [...$errors, ...$this->checkOutput($node, $schema, $aliases)];

                continue;
            }

            if ($node instanceof IfNode) {
                $errors = [
                    ...$errors,
                    ...$this->checkCondition($node, $schema, $aliases, $loopPaths),
                    ...$this->walk($node->then, $schema, $aliases, $loopPaths),
                    ...$this->walk($node->else, $schema, $aliases, $loopPaths),
                ];

                continue;
            }

            if ($node instanceof ForNode) {
                $errors = [...$errors, ...$this->checkLoop($node, $schema, $aliases)];

                $collection = $schema->collectionAt($node->path);

                if ($collection !== null) {
                    $errors = [...$errors, ...$this->walk(
                        $node->body,
                        $schema,
                        [...$aliases, $node->alias => $collection],
                        [...$loopPaths, $node->alias => $node->path],
                    )];
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, TemplateVariable>  $aliases
     * @return list<TemplateError>
     */
    private function checkOutput(VariableNode $node, TemplateSchema $schema, array $aliases): array
    {
        $aliasError = $this->checkAliasPath($node->path, $aliases, $node->offset);

        if ($aliasError !== null) {
            return $aliasError === true ? [] : [$aliasError];
        }

        if ($schema->hasScalar($node->path)) {
            return [];
        }

        if ($schema->hasCollection($node->path)) {
            return [new TemplateError('":path" is a list; use {% for item in :path %} to go through it.', ['path' => $node->path], $node->offset)];
        }

        return [new TemplateError('Unknown variable ":path".', ['path' => $node->path], $node->offset)];
    }

    /**
     * @param  array<string, TemplateVariable>  $aliases
     * @param  array<string, string>  $loopPaths
     * @return list<TemplateError>
     */
    private function checkCondition(IfNode $node, TemplateSchema $schema, array $aliases, array $loopPaths): array
    {
        $aliasError = $this->checkAliasPath($node->path, $aliases, $node->offset);

        if ($aliasError instanceof TemplateError) {
            return [$aliasError];
        }

        if ($aliasError === true) {
            // A loop field declares its values through its list: invoice.items.status.
            $alias = Str::before($node->path, '.');

            return $this->checkComparedValue($node, $schema->valuesFor($loopPaths[$alias].'.'.Str::after($node->path, '.')));
        }

        if ($schema->hasScalar($node->path)) {
            return $this->checkComparedValue($node, $schema->valuesFor($node->path));
        }

        if ($schema->hasCollection($node->path)) {
            return $node->isComparison()
                ? [new TemplateError('":path" is a list; a condition can only check that it has items.', ['path' => $node->path], $node->offset)]
                : [];
        }

        return [new TemplateError('Unknown variable ":path".', ['path' => $node->path], $node->offset)];
    }

    /**
     * A value the variable can never hold would make the condition always
     * false (or always true), so it is refused when the schema lists what the
     * variable can hold.
     *
     * @param  list<string>  $values  empty when the schema does not restrict the variable
     * @return list<TemplateError>
     */
    private function checkComparedValue(IfNode $node, array $values): array
    {
        if (! $node->isComparison() || $values === []) {
            return [];
        }

        foreach ($values as $candidate) {
            if ($node->names($candidate)) {
                return [];
            }
        }

        return [new TemplateError('":value" is not a value ":path" can have. Use one of: :values.', [
            'value' => (string) $node->value,
            'path' => $node->path,
            'values' => implode(', ', $values),
        ], $node->offset)];
    }

    /**
     * @param  array<string, TemplateVariable>  $aliases
     * @return list<TemplateError>
     */
    private function checkLoop(ForNode $node, TemplateSchema $schema, array $aliases): array
    {
        $errors = [];

        if (array_key_exists($node->alias, $aliases)) {
            $errors[] = new TemplateError('The loop name ":name" is already used by an outer loop.', ['name' => $node->alias], $node->offset);
        }

        if (in_array($node->alias, $schema->rootSegments(), true)) {
            $errors[] = new TemplateError('The loop name ":name" would hide the variables that start with it; pick another name.', ['name' => $node->alias], $node->offset);
        }

        if (! $schema->hasCollection($node->path)) {
            $errors[] = $schema->hasScalar($node->path)
                ? new TemplateError('":path" is a single value and cannot be looped over.', ['path' => $node->path], $node->offset)
                : new TemplateError('Unknown list ":path".', ['path' => $node->path], $node->offset);
        }

        return $errors;
    }

    /**
     * Resolves a path whose first segment is a loop name: null when it is not
     * one, true when the field exists, the problem otherwise.
     *
     * @param  array<string, TemplateVariable>  $aliases
     */
    private function checkAliasPath(string $path, array $aliases, int $offset): TemplateError|true|null
    {
        $alias = Str::before($path, '.');

        if (! array_key_exists($alias, $aliases)) {
            return null;
        }

        $field = Str::after($path, '.');

        if ($field === $path) {
            return new TemplateError('":name" is the loop item itself; write ":name.<field>".', ['name' => $alias], $offset);
        }

        if (str_contains($field, '.')) {
            return new TemplateError('":path" goes too deep; loop items only have their own fields.', ['path' => $path], $offset);
        }

        if (! array_key_exists($field, $aliases[$alias]->fields)) {
            return new TemplateError('":name" has no field ":field".', ['name' => $alias, 'field' => $field], $offset);
        }

        return true;
    }
}
