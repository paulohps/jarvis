<?php

namespace App\Actions\Reminders;

use App\Actions\Recurrences\SaveRecurrence;
use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\Reminder;
use App\Models\ReminderKind;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a reminder, its schedule and its open occurrence in one
 * transaction, for CreateReminder and UpdateReminder (US-9.1).
 *
 * The input: `title` (required, up to 255 characters), `note` (optional),
 * `due_at` (optional date and time, read in the creator's timezone when it
 * has no offset), `recurrence` (optional, the input of SaveRecurrence) and
 * `workspace` (the friendly id of one of the actor's workspaces with the
 * `reminders` module; left out, a new reminder goes to their default one
 * and an edited one stays where it is — given, it moves there) and
 * `assigned_to` (the friendly id of one of the `Reminder::assignees()` of
 * that workspace, who becomes responsible for it and receives
 * `reminder_due`, or null for nobody, when the creator receives it). Left
 * out, a new reminder is the creator's and an edited one keeps its
 * responsible, so clients that do not know the field cannot clear it;
 * someone who since lost the module may stay, but not be picked anew. A
 * reminder moved to a workspace its responsible is not in is refused
 * (`assigned_to`) rather than silently left without one: the move must
 * name someone of the new workspace, or nobody.
 *
 * What it is: `kind` (`reminder` or `event`), `is_all_day` and, for an
 * event that is not all day, `duration_minutes` (5 minutes to 7 days). Left
 * out, a new item is a timed reminder and an edited one keeps them. An
 * event needs a date or a repeat. An all-day item is due at
 * Reminder::ALL_DAY_TIME: `due_at` may then be a date alone, and a daily,
 * weekly or monthly repeat runs at that time.
 *
 * The schedule follows from them:
 *
 * - `recurrence` given: that rule, its errors under `recurrence.*`, and the
 *   occurrence at its `next_run_at`;
 * - only `due_at`: a `once` rule at that future instant, its errors under
 *   `due_at`, and the occurrence at it;
 * - neither: no rule and a single occurrence without `due_at`.
 *
 * Afterwards the reminder has exactly one open occurrence: the other open
 * ones are dropped, except the past ones of a repeating rule, which are runs
 * already due (overdue) rather than a superseded date. A `once` date left as
 * it was is not validated again, so an overdue reminder can still be
 * renamed. A refused schedule saves nothing.
 */
trait SavesReminder
{
    use ResolvesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function persist(SaveRecurrence $saveRecurrence, User $actor, array $input, ?Reminder $reminder): Reminder
    {
        foreach (['title', 'note', 'due_at'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = mb_trim($input[$field]);
            }
        }

        $workspace = $reminder === null || filled($input['workspace'] ?? null)
            ? $this->workspaceFor($actor, $input, Module::REMINDERS)
            : $reminder->workspace;
        $isMoving = $reminder !== null && $reminder->workspace_id !== $workspace->id;
        $assignees = Reminder::assignees($workspace);

        if ($isMoving && ! array_key_exists('assigned_to', $input) && $reminder->assigned_to_id !== null && ! $assignees->contains('id', $reminder->assigned_to_id)) {
            throw ValidationException::withMessages([
                'assigned_to' => __('The one responsible is not in this workspace: pick someone of it, or nobody.'),
            ]);
        }

        $kind = array_key_exists('kind', $input) ? $input['kind'] : ($reminder?->kind->value ?? ReminderKind::Reminder->value);
        $isAllDay = array_key_exists('is_all_day', $input) ? $input['is_all_day'] : ($reminder->is_all_day ?? false);
        $durationMinutes = array_key_exists('duration_minutes', $input) ? $input['duration_minutes'] : $reminder?->duration_minutes;
        $isTimedEvent = $kind === ReminderKind::Event->value && ! filter_var($isAllDay, FILTER_VALIDATE_BOOLEAN);

        /** @var array{title: string, note?: string|null, due_at?: string|null, recurrence?: array<string, mixed>|null, assigned_to?: string|null, kind: string, is_all_day: bool, duration_minutes: int|null} $validated */
        $validated = Validator::make([
            ...$input,
            'kind' => $kind,
            'is_all_day' => $isAllDay,
            'duration_minutes' => $isTimedEvent ? $durationMinutes : null,
        ], [
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', Rule::enum(ReminderKind::class)],
            'is_all_day' => ['boolean'],
            'duration_minutes' => [Rule::requiredIf($isTimedEvent), 'nullable', 'integer', 'min:5', 'max:10080'],
            'due_at' => ['nullable', 'date', Rule::requiredIf($kind === ReminderKind::Event->value && blank($input['recurrence'] ?? null))],
            'recurrence' => ['nullable', 'array'],
            'assigned_to' => ['nullable', 'string', Rule::in(array_filter([
                ...$assignees->pluck('friendly_id')->all(),
                $isMoving ? null : $reminder?->assignedTo?->friendly_id,
            ]))],
        ], attributes: [
            'title' => __('title'),
            'note' => __('note'),
            'due_at' => __('date and time'),
            'recurrence' => __('repeat'),
            'assigned_to' => __('responsible'),
            'kind' => __('type'),
            'is_all_day' => __('all day'),
            'duration_minutes' => __('duration'),
        ])->validate();

        $validated['is_all_day'] = (bool) $validated['is_all_day'];
        $validated['duration_minutes'] = $validated['duration_minutes'] === null ? null : (int) $validated['duration_minutes'];

        $reminder ??= new Reminder(['created_by_id' => $actor->id]);
        $reminder->setRelation('createdBy', $reminder->exists ? $reminder->createdBy : $actor);
        $this->placeIn($reminder, $workspace);

        if (array_key_exists('assigned_to', $input)) {
            $reminder->assigned_to_id = filled($validated['assigned_to'] ?? null)
                ? User::query()->where('friendly_id', $validated['assigned_to'])->value('id')
                : null;
        } elseif (! $reminder->exists) {
            $reminder->assigned_to_id = $actor->id;
        }

        $recurrenceInput = filled($validated['recurrence'] ?? null) ? $validated['recurrence'] : null;
        $errorPrefix = 'recurrence.';

        if ($recurrenceInput === null && filled($validated['due_at'] ?? null)) {
            $recurrenceInput = ['frequency' => RecurrenceFrequency::ONCE, 'starts_at' => $validated['due_at']];
            $errorPrefix = null;
        }

        if ($recurrenceInput !== null && $validated['is_all_day']) {
            $recurrenceInput = $this->atAllDayTime($recurrenceInput, $reminder);
        }

        return DB::transaction(function () use ($saveRecurrence, $reminder, $validated, $recurrenceInput, $errorPrefix): Reminder {
            $reminder->fill([
                'title' => $validated['title'],
                'note' => filled($validated['note'] ?? null) ? $validated['note'] : null,
                'kind' => $validated['kind'],
                'is_all_day' => $validated['is_all_day'],
                'duration_minutes' => $validated['duration_minutes'],
            ])->save();

            $dueAt = $recurrenceInput === null
                ? $this->clearSchedule($reminder)
                : $this->schedule($saveRecurrence, $reminder, $recurrenceInput, $errorPrefix);

            $this->syncOpenOccurrence($reminder, $dueAt);

            return $reminder->unsetRelation('recurrence')->unsetRelation('occurrences')->unsetRelation('assignedTo');
        });
    }

    /**
     * Puts the reminder in `$workspace` (saved with it).
     */
    private function placeIn(Reminder $reminder, Workspace $workspace): void
    {
        $reminder->workspace_id = $workspace->id;
        $reminder->setRelation('workspace', $workspace);
    }

    /**
     * The schedule of an all-day item: its date at Reminder::ALL_DAY_TIME —
     * or, for today once that time has passed, the instant it already has on
     * that day or else the next minute — and a daily, weekly or monthly
     * repeat at that time.
     *
     * @param  array<string, mixed>  $recurrenceInput
     * @return array<string, mixed>
     */
    private function atAllDayTime(array $recurrenceInput, Reminder $reminder): array
    {
        $timezone = $reminder->ownerTimezone();
        $frequency = $recurrenceInput['frequency'] ?? null;
        $startsAt = $recurrenceInput['starts_at'] ?? null;

        if ($frequency === RecurrenceFrequency::ONCE && is_string($startsAt) && preg_match('/^\d{4}-\d{2}-\d{2}/', $startsAt, $date) === 1) {
            $dueAt = CarbonImmutable::parse($date[0].' '.Reminder::ALL_DAY_TIME, $timezone);
            $now = now($timezone)->toImmutable();

            if ($dueAt->isSameDay($now) && $dueAt->lessThanOrEqualTo($now)) {
                $saved = $reminder->exists ? $reminder->recurrence()->first()?->starts_at : null;
                $saved = $saved === null ? null : CarbonImmutable::instance($saved)->setTimezone($timezone);
                $dueAt = $saved?->isSameDay($now) === true ? $saved : $now->addMinute()->startOfMinute();
            }

            $recurrenceInput['starts_at'] = $dueAt->format('Y-m-d\TH:i');
        }

        if (in_array($frequency, [RecurrenceFrequency::DAILY, RecurrenceFrequency::WEEKLY, RecurrenceFrequency::MONTHLY], true)) {
            $recurrenceInput['time_of_day'] = Reminder::ALL_DAY_TIME;
        }

        return $recurrenceInput;
    }

    /**
     * An undated task: no rule.
     */
    private function clearSchedule(Reminder $reminder): null
    {
        $reminder->recurrence()->delete();

        return null;
    }

    /**
     * Saves the rule and returns the instant of its open occurrence.
     *
     * @param  array<string, mixed>  $recurrenceInput
     *
     * @throws ValidationException
     */
    private function schedule(SaveRecurrence $saveRecurrence, Reminder $reminder, array $recurrenceInput, ?string $errorPrefix): CarbonImmutable
    {
        $unchangedOnceAt = $this->unchangedOnceInstant($reminder, $recurrenceInput);

        if ($unchangedOnceAt !== null) {
            return $unchangedOnceAt;
        }

        try {
            $recurrence = $saveRecurrence($reminder, $recurrenceInput);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->prefixErrors($exception->errors(), $errorPrefix));
        }

        if ($recurrence->next_run_at === null) {
            throw ValidationException::withMessages($this->prefixErrors([
                'ends_at' => [__('The schedule has no run left.')],
            ], $errorPrefix));
        }

        return CarbonImmutable::instance($recurrence->next_run_at)->utc();
    }

    /**
     * The saved `once` instant, when the input keeps it as it is.
     *
     * @param  array<string, mixed>  $recurrenceInput
     */
    private function unchangedOnceInstant(Reminder $reminder, array $recurrenceInput): ?CarbonImmutable
    {
        $recurrence = $reminder->exists ? $reminder->recurrence()->with('frequency')->first() : null;

        if ($recurrence === null
            || $recurrence->frequency->slug !== RecurrenceFrequency::ONCE
            || ($recurrenceInput['frequency'] ?? null) !== RecurrenceFrequency::ONCE
            || ! is_string($recurrenceInput['starts_at'] ?? null)) {
            return null;
        }

        try {
            $requested = CarbonImmutable::parse($recurrenceInput['starts_at'], $reminder->ownerTimezone())->utc();
        } catch (Throwable) {
            return null;
        }

        $saved = CarbonImmutable::instance($recurrence->starts_at)->utc();

        return $requested->equalTo($saved) ? $saved : null;
    }

    /**
     * Leaves the reminder with one open occurrence at `$dueAt` (undated when
     * null), keeping the overdue runs of a repeating rule.
     */
    private function syncOpenOccurrence(Reminder $reminder, ?CarbonImmutable $dueAt): void
    {
        $occurrence = $dueAt === null
            ? $reminder->occurrences()->whereNull('due_at')->whereNull('completed_at')->first()
                ?? $reminder->occurrences()->create(['due_at' => null])
            : $reminder->occurrences()->firstOrCreate(['due_at' => $dueAt]);

        $keepsOverdue = $dueAt !== null && $reminder->unsetRelation('recurrence')->isRecurring();

        $reminder->occurrences()
            ->whereNull('completed_at')
            ->whereKeyNot($occurrence->id)
            ->when($keepsOverdue, fn ($query) => $query->where(fn ($query) => $query
                ->whereNull('due_at')
                ->orWhere('due_at', '>=', now())))
            ->delete();
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function prefixErrors(array $errors, ?string $prefix): array
    {
        $prefixed = [];

        foreach ($errors as $field => $messages) {
            $key = $prefix === null ? ($field === 'starts_at' ? 'due_at' : $field) : $prefix.$field;
            $prefixed[$key] = [...$prefixed[$key] ?? [], ...$messages];
        }

        return $prefixed;
    }
}
