<?php

namespace App\Http\Resources;

use App\Services\Reminders\AgendaEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the reminders agenda: an open occurrence, or a later run of a
 * repeating reminder projected without one (`is_projected`, `occurrence`
 * null), which cannot be completed yet. Instants in ISO-8601 UTC.
 *
 * @mixin AgendaEntry
 */
class ReminderAgendaEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgendaEntry $entry */
        $entry = $this->resource;

        return [
            'due_at' => $entry->dueAt?->toIso8601String(),
            'is_projected' => $entry->isProjected(),
            'is_overdue' => $entry->isOverdue(),
            'occurrence' => $entry->occurrence === null ? null : new ReminderOccurrenceResource($entry->occurrence),
            'reminder' => new ReminderResource($entry->reminder),
        ];
    }
}
