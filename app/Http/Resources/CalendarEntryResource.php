<?php

namespace App\Http\Resources;

use App\Services\Agenda\CalendarEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item of the agenda's calendar, from any source (`reminders`,
 * `events`, `invoices`, `whatsapp`). A reminder or event names its
 * `reminder` (to edit it) and, once it has one, its `occurrence` (to
 * complete a reminder); the other sources link to their web page (`url`).
 * Shared data names its `workspace` in the color the reader sees it in
 * (WorkspaceSummaryResource); the personal sources (WhatsApp, Google) have
 * none. Instants in ISO-8601 UTC.
 *
 * @mixin CalendarEntry
 */
class CalendarEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CalendarEntry $entry */
        $entry = $this->resource;

        return [
            'key' => $entry->key,
            'source' => $entry->source,
            'title' => $entry->title,
            'subtitle' => $entry->subtitle,
            'starts_at' => $entry->startsAt->toIso8601String(),
            'ends_at' => $entry->endsAt?->toIso8601String(),
            'is_all_day' => $entry->isAllDay,
            'is_projected' => $entry->isProjected,
            'is_completable' => $entry->isCompletable(),
            'is_completed' => $entry->isCompleted(),
            'is_overdue' => $entry->isOverdue(),
            'reminder_id' => $entry->reminder?->friendly_id,
            'occurrence_id' => $entry->occurrence?->friendly_id,
            'url' => $entry->url,
            'workspace' => $entry->workspace === null ? null : new WorkspaceSummaryResource($entry->workspace),
        ];
    }
}
