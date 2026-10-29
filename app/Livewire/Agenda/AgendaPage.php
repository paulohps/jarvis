<?php

namespace App\Livewire\Agenda;

use App\Actions\Agenda\DisconnectGoogleCalendarAccount;
use App\Actions\Agenda\SetGoogleCalendarVisibility;
use App\Actions\Agenda\SyncGoogleCalendars;
use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\DeleteReminder;
use App\Actions\Reminders\ReopenReminderOccurrence;
use App\Actions\Reminders\UpdateReminder;
use App\Http\Controllers\Agenda\GoogleCalendarConnectCallbackController;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\GoogleCalendar;
use App\Models\GoogleCalendarAccount;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderKind;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\DayTimeline;
use App\Services\Reminders\ReminderOccurrenceFeed;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The agenda of the reader's workspaces (US-9.1), laid out like Google
 * Calendar: a month
 * grid, a week or a day on a 24-hour timeline, the schedule of the coming
 * days, or the tasks — the reminders' occurrences under "Pending / Overdue
 * / Completed", each with the round checkbox that completes or reopens it.
 *
 * The calendar views show everything scheduled in the system that the
 * reader may see (CalendarFeed): the agenda's own reminders and events, the
 * sending of invoices and the reader's scheduled WhatsApp messages, each
 * source with its color and a chip that hides it. "Only mine" narrows the
 * reminders and events to the ones the reader is responsible for. A reader
 * in two or more workspaces sees each item of shared data marked with its
 * workspace's color — the one they see it in — and a chip per workspace
 * that hides it; a reader in one sees the agenda as before.
 *
 * A click on an empty day or hour opens the form there; on a reminder or
 * event it opens the form to edit it; on another source's item, its page.
 * The form: reminder or event, the workspace (only for a reader with the
 * `reminders` module in two or more; their default one first), title,
 * note, who is responsible — a member of that workspace — all day or a
 * time (with a duration for an event), and an optional repeat drawn with
 * the recurrence picker.
 *
 * "Google Calendar" opens the reader's connected Google accounts: connect
 * one (read only, through Google's consent screen), show or hide each of
 * its calendars, read it again, or disconnect it.
 *
 * @property-read CarbonImmutable $anchor
 * @property-read array<string, list<CalendarEntry>> $days
 * @property-read list<string> $sources
 * @property-read Collection<int, ReminderOccurrence> $occurrences
 * @property-read array<string, int> $counts
 * @property-read Collection<int, User> $assignees
 * @property-read Collection<int, Workspace> $agendaWorkspaces
 * @property-read Collection<int, Workspace> $reminderWorkspaces
 * @property-read Collection<int, GoogleCalendarAccount> $googleAccounts
 */
#[Layout('layouts::app-shell')]
class AgendaPage extends Component
{
    public const string VIEW_DAY = 'day';

    public const string VIEW_WEEK = 'week';

    public const string VIEW_MONTH = 'month';

    public const string VIEW_SCHEDULE = 'schedule';

    public const string VIEW_TASKS = 'tasks';

    public const array VIEWS = [self::VIEW_DAY, self::VIEW_WEEK, self::VIEW_MONTH, self::VIEW_SCHEDULE, self::VIEW_TASKS];

    public const string FILTER_PENDING = ReminderOccurrenceFeed::PENDING;

    public const array FILTERS = ReminderOccurrenceFeed::FILTERS;

    /**
     * How many days the schedule view lists.
     */
    public const int SCHEDULE_DAYS = 30;

    /**
     * How many items a day of the month grid shows before "+N".
     */
    public const int MONTH_CELL_LIMIT = 3;

    /**
     * The durations offered for an event, in minutes.
     */
    public const array DURATIONS = [15, 30, 45, 60, 90, 120, 180, 240, 480];

    #[Url]
    public string $view = self::VIEW_MONTH;

    /**
     * The day the calendar is on, `Y-m-d` in the reader's timezone; empty
     * for today.
     */
    #[Url]
    public string $date = '';

    #[Url]
    public string $filter = self::FILTER_PENDING;

    #[Url]
    public bool $mine = false;

    /**
     * The source keys hidden from the calendar.
     *
     * @var list<string>
     */
    #[Url]
    public array $hide = [];

    /**
     * The friendly ids of the workspaces hidden from the calendar.
     *
     * @var list<string>
     */
    #[Url(as: 'hide_workspaces')]
    public array $hideWorkspaces = [];

    public bool $showForm = false;

    #[Locked]
    public ?string $editingId = null;

    public string $kind = 'reminder';

    /**
     * The friendly id of the workspace the form's reminder goes to.
     */
    public string $workspace = '';

    public string $title = '';

    public string $note = '';

    public string $assigned_to = '';

    public bool $hasDate = false;

    public bool $is_all_day = false;

    public string $due_at = '';

    public string $due_date = '';

    public int|string $duration_minutes = 60;

    public bool $repeats = false;

    public RecurrencePicker $recurrence;

    public ?string $status = null;

    public bool $showGoogle = false;

    public function mount(): void
    {
        $this->status = session(GoogleCalendarConnectCallbackController::STATUS_KEY);

        $googleError = session(GoogleCalendarConnectCallbackController::ERROR_KEY);

        if (is_string($googleError)) {
            $this->addError('google', $googleError);
        }

        $this->normalize();
        $this->recurrence->load($this->actor()->timezone);
    }

    /**
     * The day the calendar is on, at its midnight in the reader's timezone.
     */
    #[Computed]
    public function anchor(): CarbonImmutable
    {
        $timezone = $this->actor()->timezone;

        return $this->date === ''
            ? now($timezone)->toImmutable()->startOfDay()
            : CarbonImmutable::createFromFormat('!Y-m-d', $this->date, $timezone);
    }

    /**
     * The first day shown and the day after the last one, at midnight in
     * the reader's timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $anchor = $this->anchor;

        return match ($this->view) {
            self::VIEW_DAY => [$anchor, $anchor->addDay()],
            self::VIEW_WEEK => [$start = $anchor->startOfWeek(CarbonInterface::SUNDAY), $start->addDays(7)],
            self::VIEW_SCHEDULE => [$anchor, $anchor->addDays(self::SCHEDULE_DAYS)],
            default => [$start = $anchor->startOfMonth()->startOfWeek(CarbonInterface::SUNDAY), $start->addDays(42)],
        };
    }

    /**
     * The days shown, `Y-m-d` in the reader's timezone, each with its
     * entries — all-day ones first.
     *
     * @return array<string, list<CalendarEntry>>
     */
    #[Computed]
    public function days(): array
    {
        [$from, $to] = $this->range();
        $timezone = $this->actor()->timezone;

        $days = [];

        for ($day = $from; $day->lessThan($to); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = [];
        }

        if ($this->view === self::VIEW_TASKS) {
            return $days;
        }

        $entries = app(CalendarFeed::class)->between($this->actor(), $from->utc(), $to->utc(), $this->assigneeFilter(), $this->hide);

        foreach ($entries as $entry) {
            $day = $entry->day($timezone);

            if (array_key_exists($day, $days) && ! in_array($entry->workspace?->friendly_id, $this->hideWorkspaces, true)) {
                $days[$day][] = $entry;
            }
        }

        return $days;
    }

    /**
     * The timed entries of a day of the week or day view, laid out in lanes.
     *
     * @return list<array{entry: CalendarEntry, top: int, height: int, lane: int, lanes: int}>
     */
    public function timeline(string $day): array
    {
        $timed = array_values(array_filter($this->days[$day] ?? [], fn (CalendarEntry $entry): bool => ! $entry->isAllDay));

        return DayTimeline::layout($timed, $day, $this->actor()->timezone);
    }

    /**
     * The all-day entries of a day.
     *
     * @return list<CalendarEntry>
     */
    public function allDay(string $day): array
    {
        return array_values(array_filter($this->days[$day] ?? [], fn (CalendarEntry $entry): bool => $entry->isAllDay));
    }

    /**
     * The source keys the reader may see, for the chips that hide them.
     *
     * @return list<string>
     */
    #[Computed]
    public function sources(): array
    {
        return app(CalendarFeed::class)->keysFor($this->actor());
    }

    /**
     * The reader's workspaces whose data the agenda may show — where they
     * have the reminders, invoices or shopping module — by name.
     *
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function agendaWorkspaces(): Collection
    {
        $actor = $this->actor();

        return new Collection(collect([Module::REMINDERS, Module::INVOICES, Module::SHOPPING])
            ->flatMap(fn (string $module) => $actor->membershipsWithModule($module))
            ->map(fn (WorkspaceMembership $membership): Workspace => $membership->workspace)
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all());
    }

    /**
     * Whether the agenda tells the workspaces apart: the reader sees the
     * data of two or more.
     */
    public function showsWorkspaces(): bool
    {
        return $this->agendaWorkspaces->count() > 1;
    }

    /**
     * The hex of the color the reader sees `$workspace` in, or null when
     * the agenda does not tell the workspaces apart or the item has none
     * (a personal source).
     */
    public function workspaceHex(?Workspace $workspace): ?string
    {
        if ($workspace === null || ! $this->showsWorkspaces()) {
            return null;
        }

        return Workspace::hexOf($this->actor()->colorOf($workspace));
    }

    /**
     * The reader's workspaces with the `reminders` module, their default
     * one first, for the form.
     *
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function reminderWorkspaces(): Collection
    {
        return new Collection($this->actor()->membershipsWithModule(Module::REMINDERS)
            ->sortBy(fn (WorkspaceMembership $membership): int => $membership->is_default ? 0 : 1)
            ->map(fn (WorkspaceMembership $membership): Workspace => $membership->workspace)
            ->values()
            ->all());
    }

    /**
     * The reader's own connected Google accounts, with their calendars.
     *
     * @return Collection<int, GoogleCalendarAccount>
     */
    #[Computed]
    public function googleAccounts(): Collection
    {
        return GoogleCalendarAccount::query()
            ->where('user_id', $this->actor()->id)
            ->with('calendars')
            ->orderBy('email')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function sourceLabels(): array
    {
        return [
            CalendarFeed::REMINDERS => __('Reminders'),
            CalendarFeed::EVENTS => __('Events'),
            CalendarFeed::INVOICES => __('Invoices'),
            CalendarFeed::WHATSAPP => __('WhatsApp'),
            CalendarFeed::SHOPPING => __('Shopping'),
            CalendarFeed::GOOGLE => __('Google Calendar'),
        ];
    }

    /**
     * The occurrences of the current filter, of reminders not deleted.
     *
     * @return Collection<int, ReminderOccurrence>
     */
    #[Computed]
    public function occurrences(): Collection
    {
        return app(ReminderOccurrenceFeed::class)->list($this->actor(), $this->filter, $this->assigneeFilter());
    }

    /**
     * How many occurrences each filter holds.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return app(ReminderOccurrenceFeed::class)->counts($this->actor(), $this->assigneeFilter());
    }

    /**
     * Who may be made responsible, for the form: the members of the
     * workspace picked on it.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        $workspace = $this->formWorkspace();

        return $workspace === null ? new Collection : Reminder::assignees($workspace);
    }

    /**
     * @return array<string, string>
     */
    public function viewOptions(): array
    {
        return [
            self::VIEW_DAY => __('Day'),
            self::VIEW_WEEK => __('Week'),
            self::VIEW_MONTH => __('Month'),
            self::VIEW_SCHEDULE => __('Schedule'),
            self::VIEW_TASKS => __('Tasks'),
        ];
    }

    /**
     * The tasks' filters, with the counts of the open ones.
     *
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        return [
            ReminderOccurrenceFeed::PENDING => __('Pending').' ('.$this->counts[ReminderOccurrenceFeed::PENDING].')',
            ReminderOccurrenceFeed::OVERDUE => __('Overdue').' ('.$this->counts[ReminderOccurrenceFeed::OVERDUE].')',
            ReminderOccurrenceFeed::COMPLETED => __('Completed'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function durationOptions(): array
    {
        $options = [];

        foreach (self::DURATIONS as $minutes) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            $options[$minutes] = match (true) {
                $hours === 0 => __(':minutes min', ['minutes' => $rest]),
                $rest === 0 => __(':hours h', ['hours' => $hours]),
                default => __(':hours h :minutes min', ['hours' => $hours, 'minutes' => $rest]),
            };
        }

        return $options;
    }

    /**
     * The heading of the period shown: "October 2026", "Tuesday, October
     * 6, 2026"…
     */
    public function periodLabel(): string
    {
        [$from, $to] = $this->range();
        $locale = ['locale' => app()->getLocale()];
        $last = $to->subDay();

        return ucfirst(match ($this->view) {
            self::VIEW_DAY => $this->anchor->settings($locale)->isoFormat('dddd, LL'),
            self::VIEW_WEEK, self::VIEW_SCHEDULE => $from->isSameMonth($last)
                ? $from->settings($locale)->isoFormat('MMMM YYYY')
                : $from->settings($locale)->isoFormat('MMM').' – '.$last->settings($locale)->isoFormat('MMM YYYY'),
            default => $this->anchor->settings($locale)->isoFormat('MMMM YYYY'),
        });
    }

    /**
     * A day's heading in the schedule: "Today", "Tomorrow" or the date.
     */
    public function dayLabel(string $day): string
    {
        $timezone = $this->actor()->timezone;
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone);
        $today = now($timezone)->toImmutable()->startOfDay();

        return match (true) {
            $date->isSameDay($today) => __('Today'),
            $date->isSameDay($today->addDay()) => __('Tomorrow'),
            default => ucfirst($date->settings(['locale' => app()->getLocale()])->isoFormat('dddd, LL')),
        };
    }

    public function isToday(string $day): bool
    {
        return $day === now($this->actor()->timezone)->format('Y-m-d');
    }

    /**
     * How the reminder repeats, in the reader's language, or null for one
     * that does not.
     */
    public function repeatLabel(Reminder $reminder): ?string
    {
        if (! $reminder->isRecurring()) {
            return null;
        }

        return $this->recurrence->frequencyOptions()[$reminder->recurrence->frequency->slug] ?? null;
    }

    public function updatedView(): void
    {
        $this->normalize();
    }

    public function updatedFilter(): void
    {
        $this->normalize();
        unset($this->occurrences);
    }

    public function updatedMine(): void
    {
        $this->refreshData();
    }

    public function previous(): void
    {
        $this->move(-1);
    }

    public function next(): void
    {
        $this->move(1);
    }

    public function today(): void
    {
        $this->date = '';
        unset($this->anchor, $this->days);
    }

    /**
     * A day of the month or week grid: that day alone.
     */
    public function openDay(string $day): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return;
        }

        $this->date = $day;
        $this->view = self::VIEW_DAY;
        unset($this->anchor, $this->days);
    }

    /**
     * Shows or hides one workspace's items on the calendar.
     */
    public function toggleWorkspace(string $workspaceId): void
    {
        $this->hideWorkspaces = in_array($workspaceId, $this->hideWorkspaces, true)
            ? array_values(array_diff($this->hideWorkspaces, [$workspaceId]))
            : [...$this->hideWorkspaces, $workspaceId];

        $this->normalize();
        unset($this->days);
    }

    /**
     * Another workspace on the form: its members are who may be
     * responsible, so someone of the previous one gives way to the reader.
     */
    public function updatedWorkspace(): void
    {
        unset($this->assignees);

        if (filled($this->assigned_to) && ! $this->assignees->contains('friendly_id', $this->assigned_to)) {
            $this->assigned_to = $this->assignees->contains('id', $this->actor()->id) ? $this->actor()->friendly_id : '';
        }
    }

    /**
     * Shows or hides one source on the calendar.
     */
    public function toggleSource(string $source): void
    {
        $this->hide = in_array($source, $this->hide, true)
            ? array_values(array_diff($this->hide, [$source]))
            : [...$this->hide, $source];

        $this->normalize();
        unset($this->days);
    }

    public function openGoogle(): void
    {
        $this->resetErrorBag('google');
        $this->showGoogle = true;
    }

    /**
     * Reads an account's calendars again, and their events.
     */
    public function syncGoogle(string $accountId, SyncGoogleCalendars $syncCalendars): void
    {
        $syncCalendars($this->actor(), $this->findGoogleAccount($accountId));

        unset($this->googleAccounts, $this->sources, $this->days);
    }

    public function toggleGoogleCalendar(string $calendarId, SetGoogleCalendarVisibility $setVisibility): void
    {
        $calendar = GoogleCalendar::query()
            ->where('friendly_id', $calendarId)
            ->whereRelation('account', 'user_id', $this->actor()->id)
            ->firstOrFail();

        $setVisibility($this->actor(), $calendar, ! $calendar->is_visible);

        unset($this->googleAccounts, $this->days);
    }

    public function disconnectGoogle(string $accountId, DisconnectGoogleCalendarAccount $disconnect): void
    {
        $account = $this->findGoogleAccount($accountId);

        $disconnect($this->actor(), $account);

        $this->status = __(':email was disconnected.', ['email' => $account->email]);
        unset($this->googleAccounts, $this->sources, $this->days);
    }

    /**
     * The round checkbox: completes an open occurrence, reopens a completed
     * one.
     */
    public function toggle(string $occurrenceId, CompleteReminderOccurrence $complete, ReopenReminderOccurrence $reopen): void
    {
        $occurrence = ReminderOccurrence::query()->visibleTo($this->actor())->where('friendly_id', $occurrenceId)->firstOrFail();

        $occurrence->completed_at === null
            ? $complete($this->actor(), $occurrence)
            : $reopen($this->actor(), $occurrence);

        $this->status = null;
        $this->refreshData();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->workspace = (string) $this->actor()->defaultWorkspaceFor(Module::REMINDERS)?->friendly_id;
        $this->assigned_to = $this->actor()->friendly_id;
        $this->showForm = true;
    }

    /**
     * A click on an empty day (`$hour` null: at nine) or hour of the grid:
     * the form at that moment.
     */
    public function createAt(string $day, ?int $hour = null): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ($hour !== null && ($hour < 0 || $hour > 23))) {
            return;
        }

        $this->create();
        $this->hasDate = true;
        $this->due_date = $day;
        $this->due_at = $day.'T'.sprintf('%02d:00', $hour ?? 9);
    }

    public function edit(string $reminderId): void
    {
        $reminder = $this->findReminder($reminderId);
        $reminder->load(['workspace', 'createdBy', 'assignedTo', 'recurrence.frequency', 'recurrence.weekdays']);

        $this->resetForm();
        $this->editingId = $reminder->friendly_id;
        $this->workspace = $reminder->workspace->friendly_id;
        $this->kind = $reminder->kind->value;
        $this->title = $reminder->title;
        $this->note = (string) $reminder->note;
        $this->assigned_to = (string) $reminder->assignedTo?->friendly_id;
        $this->is_all_day = $reminder->is_all_day;
        $this->duration_minutes = $reminder->duration_minutes ?? 60;

        if ($reminder->isOnce()) {
            $startsAt = CarbonImmutable::instance($reminder->recurrence->starts_at)->setTimezone($reminder->ownerTimezone());
            $this->hasDate = true;
            $this->due_at = $startsAt->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
            $this->due_date = $startsAt->format('Y-m-d');
        } elseif ($reminder->isRecurring()) {
            $this->repeats = true;
            $this->recurrence->load($reminder->ownerTimezone(), $reminder->recurrence);
        }

        $this->showForm = true;
    }

    public function updatedHasDate(bool $hasDate): void
    {
        if ($hasDate && blank($this->due_at)) {
            $tomorrow = now()->setTimezone($this->formTimezone())->addDay()->setTime(9, 0);
            $this->due_at = $tomorrow->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
            $this->due_date = $tomorrow->format('Y-m-d');
        }
    }

    /**
     * An event always has a date.
     */
    public function updatedKind(string $kind): void
    {
        if ($kind === ReminderKind::Event->value && ! $this->hasDate) {
            $this->hasDate = true;
            $this->updatedHasDate(true);
        }
    }

    public function save(CreateReminder $createReminder, UpdateReminder $updateReminder): void
    {
        $isEvent = $this->kind === ReminderKind::Event->value;
        $hasDate = $this->hasDate || $isEvent;

        $input = [
            'workspace' => filled($this->workspace) ? $this->workspace : null,
            'kind' => $this->kind,
            'title' => $this->title,
            'note' => $this->note,
            'assigned_to' => filled($this->assigned_to) ? $this->assigned_to : null,
            'is_all_day' => $this->is_all_day,
            'duration_minutes' => $isEvent && ! $this->is_all_day ? (int) $this->duration_minutes : null,
            'due_at' => $hasDate && ! $this->repeats ? ($this->is_all_day ? $this->due_date : $this->due_at) : null,
            'recurrence' => $this->repeats ? $this->recurrence->data() : null,
        ];

        $reminder = $this->editingId === null
            ? $createReminder($this->actor(), $input)
            : $updateReminder($this->actor(), $this->findReminder($this->editingId), $input);

        $this->status = $reminder->isEvent()
            ? __('Event “:title” saved.', ['title' => $reminder->title])
            : __('Reminder “:title” saved.', ['title' => $reminder->title]);
        $this->resetForm();
        $this->refreshData();
    }

    public function delete(string $reminderId, DeleteReminder $deleteReminder): void
    {
        $reminder = $this->findReminder($reminderId);

        $deleteReminder($this->actor(), $reminder);

        $this->status = $reminder->isEvent()
            ? __('Event “:title” deleted.', ['title' => $reminder->title])
            : __('Reminder “:title” deleted.', ['title' => $reminder->title]);
        $this->resetForm();
        $this->refreshData();
    }

    public function render(): View
    {
        return view('livewire.agenda.agenda-page')->title(__('Agenda'));
    }

    /**
     * Keeps the URL's values among the valid ones.
     */
    private function normalize(): void
    {
        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = self::VIEW_MONTH;
        }

        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = self::FILTER_PENDING;
        }

        if ($this->date !== '' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1 || ! checkdate((int) substr($this->date, 5, 2), (int) substr($this->date, 8, 2), (int) substr($this->date, 0, 4)))) {
            $this->date = '';
        }

        $this->hide = array_values(array_intersect(
            CalendarFeed::SOURCES,
            array_filter($this->hide, 'is_string'),
        ));

        $this->hideWorkspaces = array_values(array_unique(array_filter($this->hideWorkspaces, 'is_string')));

        unset($this->anchor, $this->days);
    }

    /**
     * One period back or forth: a day, a week, a month or the schedule's
     * days.
     */
    private function move(int $direction): void
    {
        $anchor = $this->anchor;

        $moved = match ($this->view) {
            self::VIEW_DAY => $anchor->addDays($direction),
            self::VIEW_WEEK => $anchor->addWeeks($direction),
            self::VIEW_SCHEDULE => $anchor->addDays($direction * self::SCHEDULE_DAYS),
            default => $anchor->startOfMonth()->addMonthsNoOverflow($direction),
        };

        $this->date = $moved->format('Y-m-d');

        unset($this->anchor, $this->days);
    }

    private function refreshData(): void
    {
        unset($this->occurrences, $this->counts, $this->days);
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'workspace', 'kind', 'title', 'note', 'assigned_to', 'hasDate', 'is_all_day', 'due_at', 'due_date', 'duration_minutes', 'repeats');
        $this->recurrence->load($this->actor()->timezone);
        $this->recurrence->frequency = RecurrenceFrequency::DAILY;
        $this->resetValidation();
        unset($this->assignees);
    }

    /**
     * The workspace picked on the form, one of the reader's with the
     * `reminders` module; their default one while none is.
     */
    private function formWorkspace(): ?Workspace
    {
        return $this->reminderWorkspaces->firstWhere('friendly_id', $this->workspace)
            ?? $this->actor()->defaultWorkspaceFor(Module::REMINDERS);
    }

    /**
     * The timezone the form's date is read in: the creator's.
     */
    private function formTimezone(): string
    {
        return $this->editingId === null
            ? $this->actor()->timezone
            : $this->findReminder($this->editingId)->ownerTimezone();
    }

    /**
     * The reader under "Only mine", otherwise nobody: every reminder.
     */
    private function assigneeFilter(): ?User
    {
        return $this->mine ? $this->actor() : null;
    }

    private function findGoogleAccount(string $accountId): GoogleCalendarAccount
    {
        return GoogleCalendarAccount::query()
            ->where('friendly_id', $accountId)
            ->where('user_id', $this->actor()->id)
            ->firstOrFail();
    }

    private function findReminder(string $reminderId): Reminder
    {
        return Reminder::query()->visibleTo($this->actor())->where('friendly_id', $reminderId)->firstOrFail();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
