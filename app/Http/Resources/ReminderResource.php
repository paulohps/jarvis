<?php

namespace App\Http\Resources;

use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reminder or task (US-9.1): its workspace, in the color the reader sees
 * it in, title, note, who created it, who is responsible for it (and
 * receives `reminder_due`; the creator when nobody is) and its schedule —
 * none for an undated task, a `once` rule for a dated one, a repeating one
 * otherwise — and, when loaded, its open occurrences, to complete. Instants
 * in ISO-8601 UTC; the reader's timezone goes in `meta.timezone`.
 *
 * @mixin Reminder
 */
class ReminderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Reminder $reminder */
        $reminder = $this->resource;

        return [
            'friendly_id' => $reminder->friendly_id,
            'workspace' => new WorkspaceSummaryResource($reminder->workspace),
            'title' => $reminder->title,
            'note' => $reminder->note,
            'kind' => $reminder->kind->value,
            'is_all_day' => $reminder->is_all_day,
            'duration_minutes' => $reminder->duration_minutes,
            'is_recurring' => $reminder->isRecurring(),
            'recurrence' => $reminder->recurrence === null ? null : new RecurrenceResource($reminder->recurrence),
            'open_occurrences' => $this->whenLoaded('occurrences', fn (): array => $reminder->occurrences
                ->map(fn (ReminderOccurrence $occurrence): array => [
                    'friendly_id' => $occurrence->friendly_id,
                    'due_at' => $occurrence->due_at?->toIso8601String(),
                ])
                ->values()
                ->all()),
            'created_by' => [
                'friendly_id' => $reminder->createdBy->friendly_id,
                'name' => $reminder->createdBy->name,
            ],
            'assigned_to' => $reminder->assignedTo === null ? null : [
                'friendly_id' => $reminder->assignedTo->friendly_id,
                'name' => $reminder->assignedTo->name,
            ],
            'created_at' => $reminder->created_at?->toIso8601String(),
            'updated_at' => $reminder->updated_at?->toIso8601String(),
        ];
    }
}
