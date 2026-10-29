<?php

namespace App\View\Components\Ui;

use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use App\Services\Templates\TemplateVariableType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use InvalidArgumentException;

/**
 * The editor of a `{% %}` template (US-4.1; the visual editor ported from
 * my-finance, script in resources/js/components/template-editor): variables
 * as chips, conditions and repeats picked from the variables the context
 * offers, and "Edit as code" for the template as it is stored. A template
 * the visual editor cannot show faithfully opens as code, with the reason on
 * screen. The validation errors of the field, which carry their line and
 * column (`ValidTemplate`), are listed under it.
 *
 * The mode follows how the server renders the template:
 * - `rich`: an HTML body (`TemplateRenderer::renderHtml`): bold, italic,
 *   underline, lists, and conditions and repeats around whole paragraphs.
 *   A body written as plain text opens as the paragraphs it reads as.
 * - `plain` (default): plain text (`renderText`, a WhatsApp message), kept
 *   exactly as written, line breaks included; a condition or a repeat is a
 *   pair of tags around the selection.
 * - `inline`: the same on a single line (a title, an e-mail subject).
 *
 * Bind it like an input — `wire:model` goes on the editor itself, which
 * already waits for a pause in typing before writing. Every attribute other
 * than `placeholder` goes on the root element.
 *
 * Props:
 * - `schema`: the TemplateSchema of the context.
 * - `name`: the field name and error key (defaults to the `wire:model` property).
 * - `label`: the visible label.
 * - `value`: the text without `wire:model`.
 * - `rows`: the height of the code view (default 6).
 * - `mode`: `rich`, `plain` or `inline` (see above).
 *
 * Slots: none.
 */
class TemplateEditor extends Component
{
    /**
     * The name the loop item gets in an inserted `for` block.
     */
    public const string LOOP_ALIAS = 'item';

    public const string MODE_RICH = 'rich';

    public const string MODE_PLAIN = 'plain';

    public const string MODE_INLINE = 'inline';

    /**
     * @var list<string>
     */
    public const array MODES = [self::MODE_RICH, self::MODE_PLAIN, self::MODE_INLINE];

    public function __construct(
        public TemplateSchema $schema,
        public ?string $name = null,
        public ?string $label = null,
        public ?string $value = null,
        public int $rows = 6,
        public string $mode = self::MODE_PLAIN,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf('The template editor has no "%s" mode.', $mode));
        }
    }

    public function isRich(): bool
    {
        return $this->mode === self::MODE_RICH;
    }

    public function isInline(): bool
    {
        return $this->mode === self::MODE_INLINE;
    }

    /**
     * What the script needs: the variables of the schema, the values a
     * condition may compare them with, and its words in the reader's
     * language.
     *
     * @return array{name: string, mode: string, value: string, variables: list<array{path: string, description: string, isFlag: bool}>, collections: list<array{path: string, description: string, fields: list<array{name: string, description: string, isFlag: bool}>}>, values: object, labels: array<string, mixed>}
     */
    public function config(?string $errorKey): array
    {
        $variables = [];
        $collections = [];
        $values = [];

        foreach ($this->schema->all() as $path => $variable) {
            if (! $variable->isCollection()) {
                $variables[] = ['path' => $path, ...$this->describe($variable)];

                if ($variable->values !== []) {
                    $values[$path] = $variable->values;
                }

                continue;
            }

            $fields = [];

            foreach ($variable->fields as $fieldName => $field) {
                $fields[] = ['name' => $fieldName, ...$this->describe($field)];

                if ($field->values !== []) {
                    $values[$path.'.'.$fieldName] = $field->values;
                }
            }

            $collections[] = [
                'path' => $path,
                'description' => (string) __($variable->description),
                'fields' => $fields,
            ];
        }

        return [
            'name' => (string) ($errorKey ?? $this->fieldId($errorKey)),
            'mode' => $this->mode,
            'value' => (string) $this->value,
            'variables' => $variables,
            'collections' => $collections,
            'values' => (object) $values,
            'labels' => $this->labels(),
        ];
    }

    /**
     * The id of the editor, for its label and error list.
     */
    public function fieldId(?string $errorKey): string
    {
        return 'template-'.Str::slug($errorKey ?? $this->label ?? 'editor');
    }

    public function render(): View
    {
        return view('components.ui.template-editor');
    }

    /**
     * @return array{description: string, isFlag: bool}
     */
    private function describe(TemplateVariable $variable): array
    {
        return [
            'description' => (string) __($variable->description),
            'isFlag' => $variable->type === TemplateVariableType::Boolean,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function labels(): array
    {
        return [
            'repeatFor' => __('Repeat for each item in :list'),
            'showIfHasValue' => __('Show only if :variable has a value'),
            'showIfTrue' => __('Show only if :variable'),
            'showIfHasItems' => __('Show only if :variable has any items'),
            'showIfIs' => __('Show only if :variable is :value'),
            'showIfIsNot' => __('Show only if :variable is not :value'),
            'otherwise' => __('Otherwise'),
            'endCondition' => __('End of condition'),
            'endRepeat' => __('End of repeat'),
            'edit' => __('Edit'),
            'stopRepeating' => __('Stop repeating'),
            'removeCondition' => __('Remove condition'),
            'hasItems' => __('Has at least one item'),
            'notAvailable' => __('Not available here: :path'),
            'unsupported' => [
                'unfinished_tag' => __('an unfinished "{%" tag'),
                'else_branch' => __('an "else" branch'),
                'unknown_tag' => __('the tag ":tag"'),
                'partial_block' => __('a condition or repeat that does not wrap whole paragraphs'),
                'orphan_end' => __('an "end" tag without its opening tag'),
                'block_in_text' => __('a condition or repeat inside a sentence or a list'),
                'small_text' => __('small text'),
                'paragraph_in_text' => __('a paragraph or a list inside text'),
                'block_in_list' => __('a condition or repeat inside a list'),
            ],
        ];
    }
}
