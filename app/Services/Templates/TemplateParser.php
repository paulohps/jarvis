<?php

namespace App\Services\Templates;

use App\Services\Templates\Nodes\ForNode;
use App\Services\Templates\Nodes\IfNode;
use App\Services\Templates\Nodes\Node;
use App\Services\Templates\Nodes\TextNode;
use App\Services\Templates\Nodes\VariableNode;

/**
 * Turns tokens into the template tree (ported from my-finance).
 *
 * Output and control flow share the `{% %}` delimiter, so the keywords below
 * are reserved: a tag is variable output only when its first word is not one
 * of them.
 */
final readonly class TemplateParser
{
    /**
     * @var list<string>
     */
    public const array RESERVED = ['if', 'else', 'endif', 'for', 'endfor', 'in'];

    private const string PATH_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * `if invoice.currency == "BRL"`: a path, `==` or `!=`, and a
     * double-quoted value with no quote inside.
     */
    private const string COMPARISON_PATTERN = '/^if\s+([A-Za-z0-9_.]+)\s*(==|!=)\s*"([^"]*)"$/u';

    public function __construct(private TemplateLexer $lexer = new TemplateLexer) {}

    /**
     * @return list<Node>
     *
     * @throws TemplateSyntaxException with the line and column of the problem
     */
    public function parse(string $template): array
    {
        try {
            $tokens = $this->lexer->tokenize($template);
            $index = 0;

            return $this->parseBlock($tokens, $index, [], null);
        } catch (TemplateSyntaxException $exception) {
            throw new TemplateSyntaxException($exception->error->locate($template));
        }
    }

    /**
     * @param  list<Token>  $tokens
     * @param  list<string>  $stopWords  keywords that close the block being parsed
     * @return list<Node>
     */
    private function parseBlock(array $tokens, int &$index, array $stopWords, ?Token $opener): array
    {
        $nodes = [];
        $total = count($tokens);

        while ($index < $total) {
            $token = $tokens[$index];

            if (! $token->isTag) {
                $nodes[] = new TextNode($token->value);
                $index++;

                continue;
            }

            $keyword = $this->keyword($token->value);

            if (in_array($keyword, $stopWords, true)) {
                return $nodes;
            }

            if (in_array($keyword, ['else', 'endif', 'endfor'], true)) {
                throw TemplateSyntaxException::at($token->offset, 'Unexpected ":keyword".', ['keyword' => $keyword]);
            }

            $index++;

            $nodes[] = match ($keyword) {
                'if' => $this->parseIf($token, $tokens, $index),
                'for' => $this->parseFor($token, $tokens, $index),
                default => $this->parseVariable($token),
            };
        }

        if ($stopWords !== [] && $opener !== null) {
            throw TemplateSyntaxException::at($opener->offset, 'Missing "{% :keyword %}".', ['keyword' => end($stopWords)]);
        }

        return $nodes;
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function parseIf(Token $opener, array $tokens, int &$index): IfNode
    {
        [$path, $operator, $value] = $this->condition($opener);

        $then = $this->parseBlock($tokens, $index, ['else', 'endif'], $opener);
        $else = [];

        if ($this->keyword($tokens[$index]->value) === 'else') {
            $index++;
            $else = $this->parseBlock($tokens, $index, ['endif'], $opener);
        }

        $index++;

        return new IfNode($path, $then, $else, $opener->offset, $operator, $value);
    }

    /**
     * `{% if payer.name %}` checks that a variable has a value;
     * `{% if invoice.currency == "BRL" %}` (or `!=`) compares it with one.
     *
     * @return array{0: string, 1: IfNode::EQUALS|IfNode::NOT_EQUALS|null, 2: string|null} path, operator, value
     */
    private function condition(Token $opener): array
    {
        if (preg_match(self::COMPARISON_PATTERN, $opener->value, $matches) === 1) {
            /** @var IfNode::EQUALS|IfNode::NOT_EQUALS $operator */
            $operator = $matches[2];

            return [$this->assertPath($matches[1], $opener->offset), $operator, $matches[3]];
        }

        $parts = $this->words($opener->value);

        if (count($parts) !== 2) {
            throw TemplateSyntaxException::at($opener->offset, '"if" expects a single variable, such as {% if payer.name %}, or one compared with a quoted value, such as {% if invoice.currency == "BRL" %}.');
        }

        return [$this->assertPath($parts[1], $opener->offset), null, null];
    }

    /**
     * @param  list<Token>  $tokens
     */
    private function parseFor(Token $opener, array $tokens, int &$index): ForNode
    {
        $parts = $this->words($opener->value);

        if (count($parts) !== 4 || $parts[2] !== 'in') {
            throw TemplateSyntaxException::at($opener->offset, '"for" expects {% for item in invoice.items %}.');
        }

        $alias = $parts[1];

        if (preg_match(self::IDENTIFIER_PATTERN, $alias) !== 1) {
            throw TemplateSyntaxException::at($opener->offset, '":name" is not a valid loop name.', ['name' => $alias]);
        }

        if (in_array($alias, self::RESERVED, true)) {
            throw TemplateSyntaxException::at($opener->offset, '":name" is a reserved word and cannot name a loop.', ['name' => $alias]);
        }

        $path = $this->assertPath($parts[3], $opener->offset);

        $body = $this->parseBlock($tokens, $index, ['endfor'], $opener);
        $index++;

        return new ForNode($alias, $path, $body, $opener->offset);
    }

    private function parseVariable(Token $token): VariableNode
    {
        if ($token->value === '') {
            throw TemplateSyntaxException::at($token->offset, 'Empty "{% %}" tag.');
        }

        $parts = $this->words($token->value);

        if (count($parts) !== 1) {
            throw TemplateSyntaxException::at($token->offset, '":tag" is not a variable; write a single name such as {% payer.name %}.', ['tag' => $token->value]);
        }

        return new VariableNode($this->assertPath($parts[0], $token->offset), $token->offset);
    }

    private function assertPath(string $path, int $offset): string
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw TemplateSyntaxException::at($offset, '":path" is not a valid variable name.', ['path' => $path]);
        }

        foreach (explode('.', $path) as $segment) {
            if (in_array($segment, self::RESERVED, true)) {
                throw TemplateSyntaxException::at($offset, '":word" is a reserved word and cannot be used as a variable.', ['word' => $segment]);
            }
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function words(string $value): array
    {
        return preg_split('/\s+/', mb_trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function keyword(string $value): string
    {
        return $this->words($value)[0] ?? '';
    }
}
