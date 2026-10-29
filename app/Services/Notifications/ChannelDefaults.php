<?php

namespace App\Services\Notifications;

/**
 * What a type says on a channel, in one language, before the super admin
 * edits it (ported from my-finance). `notifications:provision` writes it into
 * `notification_templates` and keeps an untouched template in step with it;
 * a template the super admin edited (`updated_by_id`) is never overwritten.
 */
final readonly class ChannelDefaults
{
    /**
     * @param  string  $title  template of the title (the e-mail subject)
     * @param  string  $body  template of the body (light HTML)
     * @param  list<array{link_key: string, label: string}>  $actions  links of the type with their labels
     * @param  bool  $isEnabledByDefault  whether users who never chose receive it
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $actions = [],
        public bool $isEnabledByDefault = true,
    ) {}
}
