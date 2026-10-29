<?php

namespace App\Http\Resources;

use App\Models\ShoppingListSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recurring shopping list (the web's "Recurring" tab): the workspace it
 * belongs to, its name, its recurrence — each run a list's deadline — what the next
 * list starts with (`next_list_content`, with `template_items` for
 * `template_and_pending`), whether a list closes by itself at its deadline
 * (`closes_automatically`) and, when it does not, whether the next list
 * waits for it to be closed (`waits_for_close`), and its open list, when it
 * has one. Instants in ISO-8601 UTC.
 *
 * @mixin ShoppingListSchedule
 */
class ShoppingListScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ShoppingListSchedule $schedule */
        $schedule = $this->resource;
        $openList = $schedule->openList();

        return [
            'friendly_id' => $schedule->friendly_id,
            'name' => $schedule->name,
            'workspace' => new WorkspaceSummaryResource($schedule->workspace),
            'next_list_content' => $schedule->next_list_content->value,
            'closes_automatically' => $schedule->closes_automatically,
            'waits_for_close' => $schedule->waits_for_close,
            'template_items' => $schedule->template_items ?? [],
            'recurrence' => $schedule->recurrence === null ? null : new RecurrenceResource($schedule->recurrence),
            'open_list' => $openList === null ? null : [
                'friendly_id' => $openList->friendly_id,
                'name' => $openList->name,
                'due_at' => $openList->due_at?->toIso8601String(),
                'is_overdue' => $openList->isOverdue(),
            ],
            'created_at' => $schedule->created_at?->toIso8601String(),
            'updated_at' => $schedule->updated_at?->toIso8601String(),
        ];
    }
}
