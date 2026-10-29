<?php

namespace App\Http\Resources;

use App\Models\ReminderOccurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One due date of a reminder (US-9.1) — what the lists show and what is
 * completed or reopened: when it is due (null for an undated task), whether
 * it is overdue, who completed it and when, and its reminder. Instants in
 * ISO-8601 UTC.
 *
 * @mixin ReminderOccurrence
 */
class ReminderOccurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReminderOccurrence $occurrence */
        $occurrence = $this->resource;

        return [
            'friendly_id' => $occurrence->friendly_id,
            'due_at' => $occurrence->due_at?->toIso8601String(),
            'is_completed' => $occurrence->completed_at !== null,
            'is_overdue' => $occurrence->completed_at === null && $occurrence->due_at?->isPast() === true,
            'completed_at' => $occurrence->completed_at?->toIso8601String(),
            'completed_by' => $occurrence->completedBy === null ? null : [
                'friendly_id' => $occurrence->completedBy->friendly_id,
                'name' => $occurrence->completedBy->name,
            ],
            'reminder' => new ReminderResource($occurrence->reminder),
        ];
    }
}
