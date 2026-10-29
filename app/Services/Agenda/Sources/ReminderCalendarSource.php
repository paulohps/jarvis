<?php

namespace App\Services\Agenda\Sources;

use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\CalendarSource;
use App\Services\Agenda\ProjectsRuns;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The agenda's own items: every occurrence in the window — open, overdue or
 * completed — and the coming runs of the repeating ones, which have no
 * occurrence until the run before them is due — of the workspaces where the
 * reader has the `reminders` module, each entry naming its workspace.
 */
final readonly class ReminderCalendarSource implements CalendarSource
{
    use ProjectsRuns;

    public function __construct(private RecurrenceCalculator $calculator) {}

    public function keys(): array
    {
        return [CalendarFeed::REMINDERS, CalendarFeed::EVENTS];
    }

    public function isAvailableTo(User $reader): bool
    {
        return $reader->hasModule(Module::REMINDERS);
    }

    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array
    {
        $narrow = fn (Builder $query) => $query->when($assignee !== null, fn (Builder $query) => $query->where('assigned_to_id', $assignee?->id));

        $occurrences = ReminderOccurrence::query()
            ->where('due_at', '>=', $from)
            ->where('due_at', '<', $to)
            ->whereHas('reminder', fn (Builder $query) => $narrow($query->withoutTrashed()->visibleTo($reader)))
            ->with(['reminder.workspace', 'reminder.recurrence.frequency', 'reminder.recurrence.weekdays', 'reminder.assignedTo', 'reminder.createdBy', 'completedBy'])
            ->get();

        $entries = $occurrences
            ->map(fn (ReminderOccurrence $occurrence): CalendarEntry => $this->entry($occurrence->reminder, CarbonImmutable::instance($occurrence->due_at), $occurrence))
            ->all();

        $taken = [];

        foreach ($occurrences as $occurrence) {
            $taken["{$occurrence->reminder_id}@{$occurrence->due_at?->getTimestamp()}"] = true;
        }

        $repeating = Reminder::query()
            ->visibleTo($reader)
            ->when($assignee !== null, fn (Builder $query) => $query->where('assigned_to_id', $assignee?->id))
            ->whereHas('recurrence', fn (Builder $query) => $query
                ->where('is_paused', false)
                ->whereNotNull('next_run_at')
                ->whereRelation('frequency', 'slug', '!=', RecurrenceFrequency::ONCE))
            ->with(['workspace', 'recurrence.frequency', 'recurrence.weekdays', 'assignedTo', 'createdBy'])
            ->get();

        foreach ($repeating as $reminder) {
            foreach ($this->runsBetween($this->calculator, $reminder->recurrence, $from, $to) as $run) {
                if (! isset($taken["{$reminder->id}@{$run->getTimestamp()}"])) {
                    $entries[] = $this->entry($reminder, $run);
                }
            }
        }

        return array_values($entries);
    }

    private function entry(Reminder $reminder, CarbonImmutable $dueAt, ?ReminderOccurrence $occurrence = null): CalendarEntry
    {
        return new CalendarEntry(
            key: $occurrence !== null ? $occurrence->friendly_id : "{$reminder->friendly_id}@{$dueAt->getTimestamp()}",
            source: $reminder->isEvent() ? CalendarFeed::EVENTS : CalendarFeed::REMINDERS,
            title: $reminder->title,
            startsAt: $dueAt->utc(),
            endsAt: $reminder->endsAt($dueAt),
            isAllDay: $reminder->is_all_day,
            subtitle: $reminder->assignedTo?->name,
            reminder: $reminder,
            occurrence: $occurrence,
            isProjected: $occurrence === null,
            workspace: $reminder->workspace,
        );
    }
}
