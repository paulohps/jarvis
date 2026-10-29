<?php

namespace App\Observers;

use App\Actions\Whatsapp\EnsureWhatsappConversation;
use App\Models\WhatsappTarget;

/**
 * Keeps the entry of a WhatsApp chat on the unified chat screen in line
 * with its target: whenever what the entry shows changes on the target
 * (the name, the picture, the pin, the unread count…), the entry is
 * written again (EnsureWhatsappConversation); a target deleted takes its
 * entry along.
 */
class WhatsappTargetObserver
{
    /**
     * The columns the entry copies from the target.
     *
     * @var list<string>
     */
    public const array MIRRORED = [
        'name', 'nickname', 'picture_url', 'pinned_at', 'priority', 'archived_at', 'muted_until',
        'unread_count', 'marked_unread', 'last_message_at', 'blocked_at',
        ...WhatsappTarget::AI_TRIAGE_COLUMNS,
    ];

    public function __construct(private EnsureWhatsappConversation $ensureConversation) {}

    public function saved(WhatsappTarget $target): void
    {
        if (! $target->hasChat()) {
            return;
        }

        if ($target->wasRecentlyCreated || $target->wasChanged(self::MIRRORED)) {
            ($this->ensureConversation)($target);
        }
    }

    public function deleted(WhatsappTarget $target): void
    {
        $target->conversation()->delete();
    }
}
