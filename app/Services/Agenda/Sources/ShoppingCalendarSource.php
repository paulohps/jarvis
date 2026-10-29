<?php

namespace App\Services\Agenda\Sources;

use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\CalendarSource;
use App\Services\Agenda\ProjectsRuns;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The recurring shopping lists on the agenda: each list made, at its
 * deadline — open, overdue or completed — and the coming deadlines of the
 * schedules still running, which have no list yet — of the workspaces
 * where the reader has the `shopping` module, each entry with its
 * workspace.
 */
final readonly class ShoppingCalendarSource implements CalendarSource
{
    use ProjectsRuns;

    public function __construct(private RecurrenceCalculator $calculator) {}

    public function keys(): array
    {
        return [CalendarFeed::SHOPPING];
    }

    public function isAvailableTo(User $reader): bool
    {
        return $reader->hasModule(Module::SHOPPING);
    }

    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array
    {
        $lists = ShoppingList::query()
            ->visibleTo($reader)
            ->whereNotNull('shopping_list_schedule_id')
            ->where('due_at', '>=', $from)
            ->where('due_at', '<', $to)
            ->withItemCounts()
            ->with('workspace')
            ->get();

        $entries = $lists->map(fn (ShoppingList $list): CalendarEntry => new CalendarEntry(
            key: $list->friendly_id,
            source: CalendarFeed::SHOPPING,
            title: $list->name,
            startsAt: CarbonImmutable::instance($list->due_at),
            subtitle: match (true) {
                $list->isCompleted() => __('Completed'),
                $list->isOverdue() => __('Overdue'),
                default => __(':open of :total to pick', ['open' => $list->unchecked_items_count, 'total' => $list->items_count]),
            },
            url: route('shopping.show', $list),
            workspace: $list->workspace,
        ))->all();

        $taken = [];

        foreach ($lists as $list) {
            $taken["{$list->shopping_list_schedule_id}@{$list->due_at?->getTimestamp()}"] = true;
        }

        $schedules = ShoppingListSchedule::query()
            ->visibleTo($reader)
            ->whereHas('recurrence', fn (Builder $query) => $query->where('is_paused', false)->whereNotNull('next_run_at'))
            ->with(['recurrence.frequency', 'recurrence.weekdays', 'workspace'])
            ->get();

        foreach ($schedules as $schedule) {
            foreach ($this->runsBetween($this->calculator, $schedule->recurrence, $from, $to) as $run) {
                if (isset($taken["{$schedule->id}@{$run->getTimestamp()}"])) {
                    continue;
                }

                $entries[] = new CalendarEntry(
                    key: "{$schedule->friendly_id}@{$run->getTimestamp()}",
                    source: CalendarFeed::SHOPPING,
                    title: $schedule->name,
                    startsAt: $run,
                    url: route('shopping.index', ['filter' => 'recurring']),
                    isProjected: true,
                    workspace: $schedule->workspace,
                );
            }
        }

        return array_values($entries);
    }
}
