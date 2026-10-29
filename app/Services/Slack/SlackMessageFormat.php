<?php

namespace App\Services\Slack;

use Illuminate\Support\HtmlString;

/**
 * Slack's `mrkdwn` (docs.slack.dev/messaging/formatting-message-text):
 * `*bold*`, `_italic_`, `~strike~`, `` `code` ``, ```pre```, `&gt;` quotes,
 * and the angle-bracket tokens — `<@U123>` and `<@U123|name>` (a person),
 * `<#C123|name>` (a channel), `<!here>` / `<!channel>` / `<!everyone>`,
 * `<!subteam^S123|@team>`, `<!date^…|fallback>`, `<https://…|label>`.
 * The text comes with `&`, `<` and `>` already escaped.
 *
 * `toHtml()` renders it for the chat screen, `toPlain()` for previews and
 * the AI; `outgoing()` prepares what the user typed for `chat.postMessage`:
 * the three characters escaped, and `@handle` turned into `<@U123>` for the
 * people of the conversation.
 */
final class SlackMessageFormat
{
    /**
     * @param  array<string, string>  $userNames  Slack user id => name
     * @param  array<string, string>  $channelNames  Slack channel id => name
     */
    public static function toHtml(string $text, array $userNames = [], array $channelNames = []): HtmlString
    {
        $placeholders = [];
        $keep = function (string $html) use (&$placeholders): string {
            $key = "\u{E000}".count($placeholders)."\u{E001}";
            $placeholders[$key] = $html;

            return $key;
        };

        // Code first: nothing inside it is formatting.
        $text = (string) preg_replace_callback('/```(.*?)```/s', fn (array $m): string => $keep('<pre class="chat-pre">'.self::escape(trim($m[1], "\n")).'</pre>'), $text);
        $text = (string) preg_replace_callback('/`([^`\n]+)`/', fn (array $m): string => $keep('<code class="chat-code">'.self::escape($m[1]).'</code>'), $text);

        // Then the tokens, which may carry characters formatting would eat.
        $text = (string) preg_replace_callback('/<([^<>]+)>/', function (array $m) use ($keep, $userNames, $channelNames): string {
            return $keep(self::tokenHtml($m[1], $userNames, $channelNames));
        }, $text);

        $html = self::escape($text);
        $html = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<em>$1</em>', $html);
        $html = (string) preg_replace('/(?<![\w~])~(?=\S)(.+?)(?<=\S)~(?![\w~])/u', '<del>$1</del>', $html);
        $html = (string) preg_replace('/^&gt;\s?(.*)$/m', '<span class="chat-quote">$1</span>', $html);
        $html = SlackEmoji::replaceShortcodes($html);

        return new HtmlString(strtr($html, $placeholders));
    }

    /**
     * @param  array<string, string>  $userNames
     * @param  array<string, string>  $channelNames
     */
    public static function toPlain(string $text, array $userNames = [], array $channelNames = []): string
    {
        $text = (string) preg_replace_callback('/<([^<>]+)>/', function (array $m) use ($userNames, $channelNames): string {
            return self::tokenPlain($m[1], $userNames, $channelNames);
        }, $text);

        return SlackEmoji::replaceShortcodes(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * What the user typed, as Slack takes it: the special characters
     * escaped, `@handle` and `@Name` of the people given as `<@U123>`,
     * `@here` / `@channel` / `@everyone` as their tokens.
     *
     * @param  array<string, string>  $mentionable  handle or name => Slack user id
     */
    public static function outgoing(string $text, array $mentionable = []): string
    {
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        uksort($mentionable, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($mentionable as $handle => $userId) {
            $text = (string) preg_replace('/(?<![\w@])@'.preg_quote($handle, '/').'(?![\w.-])/iu', "<@{$userId}>", $text);
        }

        return (string) preg_replace('/(?<![\w@])@(here|channel|everyone)(?!\w)/', '<!$1>', $text);
    }

    /**
     * @param  array<string, string>  $userNames
     * @param  array<string, string>  $channelNames
     */
    private static function tokenHtml(string $token, array $userNames, array $channelNames): string
    {
        [$target, $label] = array_pad(explode('|', $token, 2), 2, null);

        if (str_starts_with($target, '@')) {
            $id = substr($target, 1);

            return '<span class="chat-mention">@'.self::escape($userNames[$id] ?? $label ?? $id).'</span>';
        }

        if (str_starts_with($target, '#')) {
            $id = substr($target, 1);

            return '<span class="chat-mention">#'.self::escape($channelNames[$id] ?? $label ?? $id).'</span>';
        }

        if (str_starts_with($target, '!')) {
            return '<span class="chat-mention">'.self::escape(self::broadcastLabel($target, $label)).'</span>';
        }

        $url = html_entity_decode($target, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (! preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
            return self::escape($label ?? $target);
        }

        return '<a href="'.self::escape($url).'" target="_blank" rel="noopener noreferrer" class="chat-link">'.self::escape($label ?? $url).'</a>';
    }

    /**
     * @param  array<string, string>  $userNames
     * @param  array<string, string>  $channelNames
     */
    private static function tokenPlain(string $token, array $userNames, array $channelNames): string
    {
        [$target, $label] = array_pad(explode('|', $token, 2), 2, null);

        if (str_starts_with($target, '@')) {
            return '@'.($userNames[substr($target, 1)] ?? $label ?? substr($target, 1));
        }

        if (str_starts_with($target, '#')) {
            return '#'.($channelNames[substr($target, 1)] ?? $label ?? substr($target, 1));
        }

        if (str_starts_with($target, '!')) {
            return self::broadcastLabel($target, $label);
        }

        return $label ?? $target;
    }

    private static function broadcastLabel(string $target, ?string $label): string
    {
        $name = substr($target, 1);

        if (in_array($name, ['here', 'channel', 'everyone'], true)) {
            return '@'.$name;
        }

        if (str_starts_with($name, 'subteam^')) {
            return $label ?? '@'.substr($name, 8);
        }

        if (str_starts_with($name, 'date^')) {
            return $label ?? (string) date('Y-m-d H:i', (int) explode('^', $name)[1]);
        }

        return $label ?? '@'.$name;
    }

    /**
     * Escapes for HTML without doubling what Slack already escaped.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }
}
