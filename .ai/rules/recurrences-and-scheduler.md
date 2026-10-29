---
paths:
  - 'app/Actions/Recurrences/**'
  - 'app/Services/Recurrences/**'
  - 'app/Models/Recurrence*.php'
  - 'app/Models/Weekday.php'
  - 'app/Models/Contracts/Schedulable.php'
  - 'app/Models/Concerns/HasRecurrence.php'
  - 'app/Jobs/RunScheduledSlot.php'
  - 'app/Livewire/Forms/RecurrencePicker.php'
  - 'app/View/Components/Ui/RecurrencePicker.php'
  - 'app/Rules/ValidCronExpression.php'
  - 'app/Console/**'
  - 'routes/console.php'
  - 'config/horizon.php'
  - 'tests/Feature/*Recurrence*'
  - 'tests/Feature/DispatchDueRecurrencesTest.php'
  - 'tests/Feature/*ScheduledSlot*'
---

# Recurrences engine, scheduler and console

## One scheduler for every recurring thing
`routes/console.php` runs `recurrences:dispatch` and `chats:send-scheduled` every minute and `whatsapp:prune-media` daily at 04:00 (app timezone is UTC), all `withoutOverlapping()`. Production and dev run `schedule:work` in its own container (not cron, not `schedule:run`). Never add a per-module polling command for something the recurrences engine can own; `tests/Feature/DispatchDueRecurrencesTest.php` asserts the schedule entry.

## The model: one `recurrences` row per owner
`Recurrence` is a morph (`schedulable`) with a unique `(schedulable_type, schedulable_id)`; owners use `HasRecurrence` (morphOne) and implement `App\Models\Contracts\Schedulable` (`runScheduledSlot`, `ownerTimezone`, `scheduledSlotQueue`). Current owners: `InvoiceRecurrence` (queue `invoices`), `WhatsappMessage`, `Reminder`, `ShoppingListSchedule` (`default`), `ChatScheduledMessage` (`chats`). A new owner must be added to `Relation::enforceMorphMap` in `AppServiceProvider` or `whereHasMorph('schedulable', '*')` throws.

## Frequencies are lookup rows
`RecurrenceFrequency` slugs: `once`, `daily`, `weekly` (weekdays pivot `recurrence_weekday`), `monthly` (`day_of_month` 1–31 clamped, or `is_last_day_of_month`), `custom_cron` (5-field or macro, `ValidCronExpression`). They are rows seeded by `LookupSeeder`; tests must seed it. Fields of other presets are cleared on save.

## Wall clock in the owner's timezone, instants in UTC
`time_of_day`, weekdays, day of month and cron are read in `recurrences.timezone` (copied from `ownerTimezone()` at save time — the rule creator's), so 09:00 stays 09:00 across DST; `starts_at`, `ends_at`, `next_run_at`, `last_run_at` are UTC. A skipped DST time runs at PHP's shifted time; a repeated one on its first occurrence. A user changing timezone does not move existing rules until they are saved again.

## Only `RecurrenceCalculator` computes runs
`nextRunAfter()` is strictly after `$after`, never before `starts_at`, never after `ends_at`, null when finished; `nextRuns()` feeds previews and the agenda. Don't reimplement next-run logic in a module (the picker preview, invoices preview and agenda all reuse it).

## Save through `SaveRecurrence`
It validates per preset (`once` must be in the future; `ends_at` after start), reads offset-less dates in the owner's timezone, upserts the owner's row under `lockForUpdate`, syncs weekdays and sets `next_run_at`. Callers wrap it in their own transaction and expose errors under `recurrence.*`. Livewire forms use `public RecurrencePicker $recurrence;` + `<x-ui.recurrence-picker :form="$recurrence" />` + `$this->recurrence->save($owner)`.

## Dispatch: missed runs fire once
`DispatchDueRecurrences` takes due rows (`Recurrence::due()`: not paused, `next_run_at <= now`, owner exists and not soft-deleted) in batches of 100 with `FOR UPDATE SKIP LOCKED`, each batch its own transaction. It dispatches one `RunScheduledSlot($recurrence, $slot)` `afterCommit()` on the owner's queue with `$slot` = the old `next_run_at`, then sets `last_run_at = now` and `next_run_at = nextRunAfter(now)` — after now, not after the slot, so downtime causes one catch-up run, not a burst.

## The slot is the idempotency key
A job can run twice (retries, overlap after a crash). Every `runScheduledSlot()` must be safe to repeat for the same `$slot`: a unique per (owner, slot/period) plus "return the existing row" or catch `UniqueConstraintViolationException`. Examples: invoices `period_key`, reminder occurrences `(reminder_id, due_at)` + `notified_at` claim, WhatsApp deliveries `(message, target, scheduled_for)`, chat deliveries `(message, slot_at)`, shopping lists `(schedule, due_at)`.

## Pause and resume
`PauseRecurrence` sets `is_paused` (due scope skips it, `next_run_at` untouched); `ResumeRecurrence` recomputes `next_run_at` from now, so runs missed while paused are skipped, not replayed. Deleting an owner soft-deletes it (skipped by `due()`); `DeleteInvoiceRecurrence` also pauses. `RunScheduledSlot` has `#[DeleteWhenMissingModels]`, and owners re-check `trashed()`/cancelled in `runScheduledSlot()`.

## Queues and Horizon
Supervisors in `config/horizon.php`: `default`, `notifications`, `invoices` (timeout 120), `whatsapp`, `chats` (timeout 120). A new queue name needs a supervisor there (defaults + `production` + `local`), or its jobs are never picked up. Long work (PDF, AI) belongs on a queue with a fitting timeout.

## Chat scheduled messages are a hybrid
`chats:send-scheduled` (`SendDueChatScheduledMessages`) only sends the legacy `ChatScheduledMessage`s that have `send_at` and no recurrence (created before recurrences came); every other one, `once` included, goes through the recurrences engine (`ChatScheduledMessage::runScheduledSlot` → `SendChatScheduledSlot` on `chats`). Check both paths when changing chat scheduling.

## Console commands
`app/Console/Commands`: `recurrences:dispatch`, `chats:send-scheduled`, `whatsapp:prune-media {--days=}`, `notifications:provision`, `jarvis:install {--name= --email= --password=}`. Commands use the `#[Signature]`/`#[Description]` attributes. `jarvis:install` migrates, seeds lookups and provisions; without a TTY and without the options it cannot create the super admin and exits with failure.

## Testing time
`$this->travelTo(CarbonImmutable::parse('2026-10-03 12:00:30', 'UTC'))`, owners with `timezone => 'America/Sao_Paulo'`, `Queue::fake()` to assert `RunScheduledSlot` per queue, or run `recurrences:dispatch` twice with the sync queue to prove a slot fires once. See `RecurrenceCalculatorTest.php`, `SaveRecurrenceTest.php`, `DispatchDueRecurrencesTest.php`.
