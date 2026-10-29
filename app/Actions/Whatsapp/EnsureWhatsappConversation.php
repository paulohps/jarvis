<?php

namespace App\Actions\Whatsapp;

use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\WhatsappTarget;

/**
 * Writes the entry of a WhatsApp chat on the unified chat screen from its
 * target: creates or updates the ChatConversation pointing at the
 * WhatsappTarget with everything the list shows — the kind, WhatsApp's
 * name and picture, and the state the target keeps (the nickname, the pin,
 * the priority, the archive, the mute, the unread count and mark, when the
 * latest message came and what it said, what the AI found about it).
 *
 * The target is the source of truth of a WhatsApp chat (the phone, the
 * WhatsApp screen, the apps and the webhooks write there); the entry is a
 * copy, redone by WhatsappTargetObserver at each save. A target without a
 * chat (no message either way, not pinned) has no entry: one it had goes.
 */
final readonly class EnsureWhatsappConversation
{
    public function __invoke(WhatsappTarget $target): ?ChatConversation
    {
        $target->loadMissing('account');
        $entry = $target->conversation;

        if (! $target->hasChat()) {
            $entry?->delete();
            $target->setRelation('conversation', null);

            return null;
        }

        $entry ??= new ChatConversation([
            'user_id' => $target->account->user_id,
            'chat_provider_id' => ChatProvider::idFor(ChatProvider::WHATSAPP),
        ]);

        $entry->fill([
            'kind' => $target->chatKind(),
            'name' => mb_substr($target->providerName(), 0, 200),
            'picture_url' => $target->picture_url,
            'nickname' => $target->nickname,
            'pinned_at' => $target->pinned_at,
            'priority' => $target->priority,
            'archived_at' => $target->archived_at,
            'muted_until' => $target->muted_until,
            // A target just created carries the database defaults as nulls until read again.
            'unread_count' => (int) $target->unread_count,
            'marked_unread' => (bool) $target->marked_unread,
            'last_message_at' => $target->last_message_at,
            'last_message' => $this->lastMessage($target),
            ...$target->only(WhatsappTarget::AI_TRIAGE_COLUMNS),
        ]);
        $entry->conversable()->associate($target);
        $entry->save();

        $target->setRelation('conversation', $entry);

        return $entry;
    }

    /**
     * What the list shows of the latest message, as the WhatsApp screen
     * sums it up: its text, or the kind of thing it is.
     *
     * @return array{text: string|null, from_me: bool, sender: string|null, kind: string}|null
     */
    private function lastMessage(WhatsappTarget $target): ?array
    {
        $message = $target->chatMessages()->orderByDesc('sent_at')->orderByDesc('id')->first();

        if ($message === null) {
            return null;
        }

        $summary = $message->summary();

        return [
            'text' => $message->isRevoked() ? __('Message deleted') : ($summary['text'] ?? $summary['label']),
            'from_me' => $message->from_me,
            'sender' => $message->from_me ? null : ($target->isGroup() ? $message->senderLabel() : null),
            'kind' => $message->mediaKind() ?? 'text',
        ];
    }
}
