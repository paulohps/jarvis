<?php

namespace App\Services\Notifications;

use Closure;

/**
 * A destination a notification type may link to (ported from my-finance).
 * The super admin never types a URL: an action picks one of the links the
 * type declares and gives it a label. The URL is built per recipient from
 * the context, and a link that does not apply to a recipient (null) is left
 * out of their notification.
 */
final readonly class NotificationLink
{
    /**
     * @param  string  $label  English source text of the default label
     * @param  Closure(array<string, mixed>, object): ?string  $url
     */
    private function __construct(
        public string $label,
        private Closure $url,
    ) {}

    /**
     * @param  string  $label  English source text of the default label
     * @param  Closure(array<string, mixed>, object): ?string  $url  the context and the notifiable
     */
    public static function make(string $label, Closure $url): self
    {
        return new self($label, $url);
    }

    /**
     * The URL for one recipient, or null when the link does not apply to them.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolve(array $context, object $notifiable): ?string
    {
        return ($this->url)($context, $notifiable);
    }
}
