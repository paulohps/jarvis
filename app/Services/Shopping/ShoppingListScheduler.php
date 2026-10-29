<?php

namespace App\Services\Shopping;

use App\Events\ShoppingListChanged;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListNextContent;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The lists of a recurring shopping list (ShoppingListSchedule). Each run
 * of its recurrence is the deadline of one list:
 *
 * - while a list is open, nothing new is made — unless its deadline passed
 *   and the schedule does not wait for it to be closed;
 * - a list completed (by hand, or at its deadline when the schedule closes
 *   by itself) is archived, and the next one is made at once for the first
 *   run after now that has no list yet;
 * - the next list starts with the schedule's `next_list_content`, taken
 *   from the last list completed (a list still open keeps its items),
 *   belongs to the schedule's workspace and inherits being the default
 *   list when that workspace has no other default.
 *
 * Lists are unique per schedule and deadline, so a slot run twice makes
 * one list.
 */
final readonly class ShoppingListScheduler
{
    /**
     * How many runs are skipped at most looking for one without a list.
     */
    private const int MAX_SKIPPED_RUNS = 50;

    public function __construct(private RecurrenceCalculator $calculator) {}

    /**
     * The deadline `$slot` is reached: its list closes when the schedule
     * closes by itself, and the next one is made when due.
     */
    public function reachDeadline(ShoppingListSchedule $schedule, CarbonImmutable $slot): void
    {
        $list = $schedule->lists()->where('due_at', $slot)->first();

        if ($list !== null && $schedule->closes_automatically && ! $list->isCompleted()) {
            $this->complete($list, null);
        }

        $this->ensureNextList($schedule);
    }

    /**
     * Completes a list, archiving it, and has its schedule make the next.
     */
    public function complete(ShoppingList $list, ?User $actor): ShoppingList
    {
        if (! $list->isCompleted()) {
            $wasDefault = $list->is_default;

            $list->update([
                'completed_at' => now(),
                'completed_by_id' => $actor?->id,
                'archived_at' => $list->archived_at ?? now(),
                'is_default' => false,
            ]);

            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_ARCHIVED);

            $schedule = $list->schedule;

            if ($schedule !== null && ! $schedule->trashed()) {
                $this->ensureNextList($schedule, $wasDefault);
            }
        }

        return $list;
    }

    /**
     * Makes the next list when the schedule has none to use: no list open
     * with its deadline ahead, and — when it waits for the close — none
     * open at all.
     */
    public function ensureNextList(ShoppingListSchedule $schedule, bool $makeDefault = false): ?ShoppingList
    {
        $recurrence = $schedule->recurrence()->with(['frequency', 'weekdays'])->first();

        if ($schedule->trashed() || $recurrence === null || $recurrence->is_paused) {
            return null;
        }

        return DB::transaction(function () use ($schedule, $recurrence, $makeDefault): ?ShoppingList {
            ShoppingListSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();

            $open = $schedule->lists()->whereNull('completed_at')->notArchived()->get();

            if ($open->contains(fn (ShoppingList $list): bool => $list->due_at === null || $list->due_at->isFuture())) {
                return null;
            }

            if ($open->isNotEmpty() && $schedule->waits_for_close) {
                return null;
            }

            $dueAt = $this->calculator->nextRunAfter($recurrence, now()->toImmutable()->subSecond());

            for ($skipped = 0; $dueAt !== null && $skipped < self::MAX_SKIPPED_RUNS; $skipped++) {
                if ($schedule->lists()->where('due_at', $dueAt)->doesntExist()) {
                    break;
                }

                $dueAt = $this->calculator->nextRunAfter($recurrence, $dueAt);
            }

            if ($dueAt === null || $schedule->lists()->where('due_at', $dueAt)->exists()) {
                return null;
            }

            $previous = $schedule->lists()->whereNotNull('completed_at')->first();

            $list = ShoppingList::query()->create([
                'workspace_id' => $schedule->workspace_id,
                'shopping_list_schedule_id' => $schedule->id,
                'name' => $this->listName($schedule, $dueAt),
                'due_at' => $dueAt,
                'created_by_id' => $schedule->created_by_id,
            ]);

            $this->fill($list, $schedule, $previous);

            if ($makeDefault && ShoppingList::defaultIn($schedule->workspace_id) === null) {
                $list->update(['is_default' => true]);
            }

            ShoppingListChanged::dispatch($list->friendly_id, ShoppingListChanged::LIST_CREATED);

            return $list;
        });
    }

    /**
     * "Market · Fri, Oct 9", the date of the deadline in the owner's
     * timezone.
     */
    public function listName(ShoppingListSchedule $schedule, CarbonImmutable $dueAt): string
    {
        $date = $dueAt->setTimezone($schedule->ownerTimezone())->settings(['locale' => app()->getLocale()])->isoFormat('ddd, D MMM');

        return mb_substr($schedule->name.' · '.$date, 0, 100);
    }

    /**
     * The items the new list starts with, unchecked, in order.
     */
    private function fill(ShoppingList $list, ShoppingListSchedule $schedule, ?ShoppingList $previous): void
    {
        $previousItems = $previous?->items()->get() ?? collect();
        $pending = $previousItems->filter(fn (ShoppingListItem $item): bool => ! $item->isChecked());

        /** @var list<array{name: string, quantity_note: string|null}> $items */
        $items = match ($schedule->next_list_content) {
            ShoppingListNextContent::Empty => [],
            ShoppingListNextContent::All => $this->itemRows($previousItems->sortBy('position')->all()),
            ShoppingListNextContent::Pending => $this->itemRows($pending->all()),
            ShoppingListNextContent::TemplateAndPending => [
                ...array_map(fn (string $name): array => ['name' => $name, 'quantity_note' => null], $schedule->template_items ?? []),
                ...$this->itemRows($pending->all()),
            ],
        };

        $seen = [];
        $position = 0;

        foreach ($items as $item) {
            $key = mb_strtolower(mb_trim($item['name']));

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $list->items()->create([
                'name' => $item['name'],
                'quantity_note' => $item['quantity_note'],
                'position' => $position++,
                'created_by_id' => $schedule->created_by_id,
            ]);
        }
    }

    /**
     * @param  array<int, ShoppingListItem>  $items
     * @return list<array{name: string, quantity_note: string|null}>
     */
    private function itemRows(array $items): array
    {
        return array_values(array_map(fn (ShoppingListItem $item): array => [
            'name' => $item->name,
            'quantity_note' => $item->quantity_note,
        ], $items));
    }
}
