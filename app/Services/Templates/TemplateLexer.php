<?php

namespace App\Services\Templates;

/**
 * Splits a template into literal text and `{% ... %}` tags.
 *
 * `{{ ... }}` is not syntax here: it comes out as plain text, which keeps Blade
 * habits from silently doing something unexpected. Offsets count characters,
 * not bytes.
 */
final class TemplateLexer
{
    public const string OPEN = '{%';

    public const string CLOSE = '%}';

    /**
     * @return list<Token>
     *
     * @throws TemplateSyntaxException
     */
    public function tokenize(string $template): array
    {
        $tokens = [];
        $offset = 0;
        $length = mb_strlen($template);

        while ($offset < $length) {
            $open = mb_strpos($template, self::OPEN, $offset);

            if ($open === false) {
                $tokens[] = Token::text(mb_substr($template, $offset), $offset);

                break;
            }

            if ($open > $offset) {
                $tokens[] = Token::text(mb_substr($template, $offset, $open - $offset), $offset);
            }

            $close = mb_strpos($template, self::CLOSE, $open + 2);

            if ($close === false) {
                throw TemplateSyntaxException::at($open, 'Unclosed "{%" tag.');
            }

            $tokens[] = Token::tag(mb_trim(mb_substr($template, $open + 2, $close - $open - 2)), $open);

            $offset = $close + 2;
        }

        return $tokens;
    }
}
