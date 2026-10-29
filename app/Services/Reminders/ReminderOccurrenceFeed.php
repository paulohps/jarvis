<?php

namespace App\Services\Reminders;

use App\Models\Reminder;
use App\Models\ReminderKind;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The occurrences under each filter of US-9.1 — "Pending", "Overdue" and
 * "Completed" — of the reminders not deleted of the workspaces the reader
 * sees (all of theirs with the `reminders` module, together) (events are not tasks: they
 * only show on the agenda's calendar), by the scopes of
 * ReminderOccurrence, so each one is in exactly one filter. The web list and
 * the API read them from here, so both show the same sets in the same order:
 * open ones by due date (undated last), completed ones the latest first, up
 * to COMPLETED_LIMIT. Given an `$assignee`, only the reminders that person
 * is responsible for ("only mine").
 *
 * The agenda lays the open occurrences out by day instead: the overdue ones
 * first, then each day, then the undated ones, with the later runs of the
 * repeating reminders projected for the next AGENDA_PROJECTION_DAYS.
 */
final readonly class ReminderOccurrenceFeed
{
    public function __construct(private RecurrenceCalculator $calculator) {}

    public const string PENDING = 'pending';

    public const string OVERDUE = 'overdue';

    public const string COMPLETED = 'completed';

    public const array FILTERS = [self::PENDING, self::OVERDUE, self::COMPLETED];

    /**
     * How many completed occurrences are listed, the latest first.
     */
    public const int COMPLETED_LIMIT = 100;

    /**
     * How many days ahead the agenda projects the runs of a repeating
     * reminder that have no occurrence yet.
     */
    public const int AGENDA_PROJECTION_DAYS = 14;

    /**
     * The agenda group of the overdue occurrences, shown first.
     */
    public const string AGENDA_OVERDUE = 'overdue';

    /**
     * The agenda group of the undated tasks, shown last.
     */
    public const string AGENDA_UNDATED = 'undated';

    /**
     * The occurrences of a filter in display order, with their reminder,
     * its schedule and who completed them.
     *
     * @return Collection<int, ReminderOccurrence>
     */
    public function list(User $reader, string $filter, ?User $assignee = null): Collection
    {
        $query = $this->query($reader, $filter, $assignee)
            ->with(['reminder.workspace', 'reminder.recurrence.frequency', 'reminder.recurrence.weekdays', 'reminder.createdBy', 'reminder.assignedTo', 'completedBy']);

        return match ($filter) {
            self::COMPLETED => $query->latest('completed_at')->latest('id')->limit(self::COMPLETED_LIMIT)->get(),
            default => $query->orderByRaw('due_at asc nulls last')->orderBy('id')->get(),
        };
    }

    /**
     * How many occurrences each filter holds.
     *
     * @return array<string, int>
     */
    public function counts(User $reader, ?User $assignee = null): array
    {
        return array_combine(self::FILTERS, array_map(
            fn (string $filter): int => $this->query($reader, $filter, $assignee)->count(),
            self::FILTERS,
        ));
    }

    /**
     * @return Builder<ReminderOccurrence>
     */
    public function query(User $reader, string $filter, ?User $assignee = null): Builder
    {
        $query = ReminderOccurrence::query()
            ->whereHas('reminder', fn (Builder $query) => $query
                ->withoutTrashed()
                ->visibleTo($reader)
                ->where('kind', ReminderKind::Reminder)
                ->when($assignee !== null, fn (Builder $query) => $query->where('assigned_to_id', $assignee?->id)));

        return match ($filter) {
            self::OVERDUE => $query->overdue(),
            self::COMPLETED => $query->completed(),
            default => $query->pending(),
        };
    }

    /**
     * The open occurrences by agenda group — AGENDA_OVERDUE, each day as
     * `Y-m-d` in the reader's timezone in order, then AGENDA_UNDATED — with
     * the runs of the repeating reminders projected up to
     * AGENDA_PROJECTION_DAYS after today. Empty groups are left out.
     *
     * @return array<string, list<AgendaEntry>>
     */
    public function agenda(User $reader, ?User $assignee = null): array
    {
        $timezone = $reader->timezone;
        $open = $this->list($reader, self::OVERDUE, $assignee)->concat($this->list($reader, self::PENDING, $assignee));
        $projectUntil = now()->setTimezone($timezone)->startOfDay()->addDays(self::AGENDA_PROJECTION_DAYS + 1)->toImmutable();

        $entries = $open
            ->map(fn (ReminderOccurrence $occurrence): AgendaEntry => new AgendaEntry(
                $occurrence->reminder,
                $occurrence->due_at === null ? null : CarbonImmutable::instance($occurrence->due_at)->utc(),
                $occurrence,
            ))
            ->concat($open
                ->filter(fn (ReminderOccurrence $occurrence): bool => $occurrence->due_at !== null)
                ->groupBy('reminder_id')
                ->flatMap(fn (Collection $occurrences): array => $this->projectedRuns(
                    $occurrences->first()->reminder,
                    CarbonImmutable::instance($occurrences->max('due_at'))->utc(),
                    $projectUntil,
                )));

        $groups = [self::AGENDA_OVERDUE => []];

        foreach ($entries->sortBy(fn (AgendaEntry $entry): array => [$entry->dueAt === null ? 1 : 0, $entry->dueAt?->getTimestamp() ?? 0]) as $entry) {
            $group = match (true) {
                $entry->dueAt === null => self::AGENDA_UNDATED,
                $entry->isOverdue() => self::AGENDA_OVERDUE,
                default => $entry->dueAt->setTimezone($timezone)->format('Y-m-d'),
            };

            $groups[$group][] = $entry;
        }

        return array_filter($groups);
    }

    /**
     * The runs of a repeating reminder after its last open occurrence and
     * before `$until`, which have no occurrence yet.
     *
     * @return list<AgendaEntry>
     */
    private function projectedRuns(Reminder $reminder, CarbonImmutable $after, CarbonImmutable $until): array
    {
        $recurrence = $reminder->recurrence;

        if ($recurrence === null || ! $reminder->isRecurring() || $recurrence->is_paused) {
            return [];
        }

        $runs = [];

        while (($after = $this->calculator->nextRunAfter($recurrence, $after)) !== null && $after->lessThan($until)) {
            $runs[] = new AgendaEntry($reminder, $after);
        }

        return $runs;
    }
}
