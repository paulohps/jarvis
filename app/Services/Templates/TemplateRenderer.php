<?php

namespace App\Services\Templates;

use App\Services\Templates\Nodes\ForNode;
use App\Services\Templates\Nodes\IfNode;
use App\Services\Templates\Nodes\Node;
use App\Services\Templates\Nodes\TextNode;
use App\Services\Templates\Nodes\VariableNode;
use Countable;
use DateTimeInterface;
use Stringable;

/**
 * Renders a parsed template against a plain-array context (ported from
 * my-finance).
 *
 * Every value is HTML-escaped before it reaches the output, and a body is
 * sanitized afterwards, so neither the data nor the operator's markup can
 * inject anything executable.
 *
 * The renderer does not translate nor format: the context arrives built in
 * the recipient's language and timezone (TemplateContextBuilder), with dates
 * and amounts already written out. A boolean is for conditions and prints nothing; a date that
 * slipped through prints as `Y-m-d`.
 */
final readonly class TemplateRenderer
{
    /**
     * Guard against a context that accidentally carries thousands of rows.
     */
    public const int MAX_LOOP_ITERATIONS = 500;

    public function __construct(
        private TemplateParser $parser = new TemplateParser,
        private HtmlSanitizer $sanitizer = new HtmlSanitizer,
    ) {}

    /**
     * Render a body: light formatting is kept, everything else is stripped.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxException
     */
    public function renderHtml(string $template, array $context): string
    {
        return $this->sanitizer->sanitize($this->render($template, $context));
    }

    /**
     * Render a title or an action label, which are plain text wherever they
     * are shown.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxException
     */
    public function renderText(string $template, array $context): string
    {
        $rendered = strip_tags($this->render($template, $context));

        return mb_trim(html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws TemplateSyntaxException
     */
    public function render(string $template, array $context): string
    {
        return $this->renderNodes($this->parser->parse($template), $context);
    }

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, mixed>  $context
     */
    private function renderNodes(array $nodes, array $context): string
    {
        $output = '';

        foreach ($nodes as $node) {
            $output .= match (true) {
                $node instanceof TextNode => $node->text,
                $node instanceof VariableNode => e($this->stringify(data_get($context, $node->path))),
                $node instanceof IfNode => $this->renderIf($node, $context),
                $node instanceof ForNode => $this->renderFor($node, $context),
                default => '',
            };
        }

        return $output;
    }

    /**
     * Without an operator the variable only has to have a value; with one,
     * its text is compared with the value the condition names.
     *
     * @param  array<string, mixed>  $context
     */
    private function renderIf(IfNode $node, array $context): string
    {
        $value = data_get($context, $node->path);

        $holds = $node->isComparison()
            ? $node->names($this->stringify($value)) === ($node->operator === IfNode::EQUALS)
            : $this->isTruthy($value);

        return $this->renderNodes($holds ? $node->then : $node->else, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderFor(ForNode $node, array $context): string
    {
        $items = data_get($context, $node->path);

        if (! is_iterable($items)) {
            return '';
        }

        $output = '';
        $iterations = 0;

        foreach ($items as $item) {
            if ($iterations++ >= self::MAX_LOOP_ITERATIONS) {
                break;
            }

            $output .= $this->renderNodes($node->body, [...$context, $node->alias => $item]);
        }

        return $output;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }

    /**
     * Truthiness without PHP's surprises: only null, false, a blank string
     * and an empty list are false, so a numeric zero still renders its
     * branch.
     */
    private function isTruthy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false => false,
            is_string($value) => mb_trim($value) !== '',
            is_array($value) => $value !== [],
            $value instanceof Countable => count($value) > 0,
            default => true,
        };
    }
}
