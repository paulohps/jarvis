<?php

namespace App\Services\Notifications\Mail;

use App\Services\Notifications\RenderedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The e-mail of a notification center type (ported from my-finance): the
 * rendered title as subject and heading, the sanitized body, and one button
 * per action (Laravel's `MailMessage` holds a single action, hence the own
 * view). Rendered in the recipient's language, like the rest of the
 * notification.
 */
final class CenterMailMessage extends MailMessage
{
    public const string VIEW = 'mail.notifications.center';

    /**
     * @param  string|null  $preferencesUrl  where a user turns the e-mail off; null for an address without an account
     */
    public static function fromRendered(RenderedNotification $notification, ?string $preferencesUrl = null): self
    {
        return (new self)
            ->subject($notification->title)
            ->markdown(self::VIEW, [
                'title' => $notification->title,
                'body' => self::asHtmlBlock($notification->body),
                'buttons' => $notification->actions,
                'preferencesUrl' => $preferencesUrl,
            ]);
    }

    /**
     * The body as one Markdown HTML block: a blank line would end the block
     * and let Markdown reinterpret the rest of the (already safe) HTML.
     */
    private static function asHtmlBlock(string $body): string
    {
        $lines = array_filter(
            array_map(trim(...), preg_split('/\R/u', $body) ?: []),
            fn (string $line): bool => $line !== '',
        );

        return '<div>'.implode("\n", $lines).'</div>';
    }
}
