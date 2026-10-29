<?php

namespace App\Support\Whatsapp;

use Illuminate\Support\HtmlString;

/**
 * A WhatsApp text as the chat shows it, the way WhatsApp formats it:
 * `*bold*`, `_italic_`, `~strikethrough~`, `` `inline code` ``,
 * ```` ```monospace``` ````, `> ` quotes, links that open in a new tab and
 * the people it mentions (`@` and their number) as their name, marked.
 * Everything else is escaped.
 */
final class MessageFormat
{
    /**
     * @param  array<string, string>  $mentions  the name to show for each mentioned number (`@` and digits)
     */
    public static function toHtml(string $text, array $mentions = []): HtmlString
    {
        $tokens = [];
        $keep = function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\u{E000}".(count($tokens) - 1)."\u{E001}";
        };

        $text = (string) preg_replace_callback('/```([\s\S]+?)```/u',
            fn (array $match): string => $keep('<code class="rounded bg-fill px-1 font-mono text-[0.85em]">'.e($match[1]).'</code>'), $text);
        $text = (string) preg_replace_callback('/`([^`\n]+)`/u',
            fn (array $match): string => $keep('<code class="rounded bg-fill px-1 font-mono text-[0.85em]">'.e($match[1]).'</code>'), $text);
        $text = (string) preg_replace_callback('~\bhttps?://[^\s<>"]+[^\s<>".,;:!?)\]]~iu',
            fn (array $match): string => $keep('<a href="'.e($match[0]).'" target="_blank" rel="noopener noreferrer" class="break-all text-chat-link hover:underline">'.e($match[0]).'</a>'), $text);

        foreach ($mentions as $mention => $name) {
            $text = (string) preg_replace_callback('/(?<![\w@])'.preg_quote($mention, '/').'(?!\d)/u',
                fn (): string => $keep('<span class="font-medium text-chat-link" data-chat-mention>@'.e($name).'</span>'), $text);
        }

        $html = e($text);

        foreach (['\*' => 'strong', '_' => 'em', '~' => 's'] as $marker => $tag) {
            $html = (string) preg_replace(
                '/(?<![\p{L}\p{N}'.$marker.'])'.$marker.'(?=\S)([^\n]*?\S)'.$marker.'(?![\p{L}\p{N}'.$marker.'])/u',
                "<{$tag}>$1</{$tag}>",
                $html,
            );
        }

        $html = (string) preg_replace('/^&gt; ?(.*)(?:\n|$)/mu', '<span class="block border-l-4 border-chat-meta pl-2 opacity-80">$1</span>', $html);

        $html = (string) preg_replace_callback("/\u{E000}(\d+)\u{E001}/u", fn (array $match): string => $tokens[(int) $match[1]], $html);

        return new HtmlString($html);
    }
}
