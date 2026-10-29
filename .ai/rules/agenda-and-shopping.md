---
paths:
  - 'app/Actions/Reminders/**'
  - 'app/Actions/Agenda/**'
  - 'app/Actions/Shopping/**'
  - 'app/Services/Agenda/**'
  - 'app/Services/Reminders/**'
  - 'app/Services/Shopping/**'
  - 'app/Models/Reminder*.php'
  - 'app/Models/GoogleCalendar*.php'
  - 'app/Models/ShoppingList*.php'
  - 'app/Events/ShoppingListChanged.php'
  - 'app/Livewire/Agenda/**'
  - 'app/Livewire/Shopping/**'
  - 'app/Http/Controllers/Agenda/**'
  - 'app/Http/Controllers/Api/V1/Agenda/**'
  - 'app/Http/Controllers/Api/V1/Reminders/**'
  - 'app/Http/Controllers/Api/V1/Shopping/**'
  - 'resources/views/livewire/agenda/**'
  - 'resources/views/livewire/shopping/**'
  - 'tests/Feature/Agenda*'
  - 'tests/Feature/Reminder*'
  - 'tests/Feature/Shopping*'
  - 'tests/Feature/ApiReminders*'
  - 'tests/Feature/ApiShopping*'
---

# Agenda (reminders), Google Calendar and shopping

## "Agenda" is still `reminders` in code
The UI says Agenda and lives at `/agenda` (`/reminders` redirects), but the module slug (`Module::REMINDERS`), route names (`reminders.*`), notification type (`reminder_due`) and the apps keep `reminders`. Don't rename them: links, the apps and stored preferences depend on them.

## Reminders, events and occurrences
A `Reminder` (`kind`: `ReminderKind::Reminder` or `Event`) has one `ReminderOccurrence` per due date (`due_at` null for an undated task), unique `(reminder_id, due_at)`. Events take `duration_minutes` or `is_all_day` and are never completed nor shown as tasks. All-day items are due at `Reminder::ALL_DAY_TIME` (09:00 on the creator's clock) so the notice does not come at midnight. Saving rules live in `SavesReminder` (one transaction: reminder + recurrence + exactly one open occurrence).

## Schedule input
`due_at` alone becomes a `once` recurrence; `recurrence` (the `SaveRecurrence` input) gives the rule, errors under `recurrence.*`; neither = undated task, no rule. Dates without offset are read in the creator's timezone. A `once` date left unchanged is not re-validated, so an overdue reminder can still be renamed.

## Due notice: once per occurrence, to the responsible
`Reminder::runScheduledSlot()` firstOrCreates the occurrence for the slot and claims it with a conditional update on `notified_at IS NULL AND completed_at IS NULL`; only the claimer sends `ReminderDue`. The recipient is `assigned_to` if they still see the reminder, else the creator (`Reminder::recipient()`). Defaults: in-app, push and WhatsApp on, e-mail off. Then `ensureNextOccurrence()` makes the next one.

## Completing creates the next occurrence once
`CompleteReminderOccurrence` creates the next occurrence at the recurrence's next run; completing again is a no-op and the unique protects concurrent requests. Any workspace member with the module may complete; the occurrences of a deleted reminder cannot.

## Assignee rules
`assigned_to` is a friendly id from `Reminder::assignees()` of the reminder's workspace. Left out on update it keeps the current one (old clients cannot clear it). Moving a reminder to a workspace its responsible is not in is refused under `assigned_to`, never silently cleared.

## The calendar is a feed of sources
`CalendarFeed` merges `CalendarSource`s (`app/Services/Agenda/Sources/*`): reminders, invoices (sent, pending, upcoming runs), the reader's own scheduled WhatsApp messages, shopping deadlines and Google Calendar, each only for readers with its module. Future runs of schedules come from `ProjectsRuns` (after now, capped at 400 per window). A new schedulable module that should show on the agenda adds a source there; the web page and `GET /api/v1/agenda` both read the feed.

## Google Calendar is per user, read-only
`GoogleCalendarAccount` is private to its user (other workspace members never see its events). Scopes: `calendar.events.readonly` + `calendar.calendarlist.readonly` (`GoogleCalendarClient::SCOPES`), offline OAuth with `prompt=consent` via `reminders.google.redirect` → `/agenda/google/callback` (must be an authorized redirect URI in the Google client, besides `/auth/google/callback` and `/mail-services/google/callback`). Refresh tokens are encrypted and hidden.

## Google failures never break the agenda
`invalid_grant` marks the account failed (`failed_at`, `last_error`) and the UI shows a reconnect banner; a calendar that cannot be read is left out. Reconnecting the same e-mail replaces the token and clears the failure. Disconnect revokes at Google best-effort and removes the account anyway.

## Cache Google events as arrays
Events are cached `EVENTS_TTL` (300 s) per window. `cache.serializable_classes` is `false`, so cached objects come back as `__PHP_Incomplete_Class` — cache only scalars/arrays (`GoogleEvent` is rebuilt on read). "Refresh" / `SyncGoogleCalendars` drops the cache and keeps each calendar's `is_visible`. Tests fake `Http` on `GoogleCalendarClient::TOKEN_URL`/`API_URL` (`tests/Feature/AgendaGoogleCalendarTest.php`).

## Shopping lists are workspace-shared and realtime
Lists belong to a workspace (members with `shopping`). Every change dispatches `ShoppingListChanged` (`ShouldDispatchAfterCommit`) on the private channel `shopping-lists.{friendly_id}`; the payload is a hint, screens reload the list. Keep the after-commit behavior so listeners never read unsaved state.

## Offline last-write-wins on items
`client_updated_at` (the device clock of the change) decides conflicts: `ToggleShoppingListItem` only applies a write not older than the stored one. Checked items always sink to the end in check order, whatever `position`; `ReorderShoppingListItems` ignores unknown ids and keeps items added meanwhile after the reordered ones.

## One default list per workspace
`is_default` has a partial unique index on `workspace_id`; `SetDefaultShoppingList` swaps in one transaction. Archived lists cannot be default. Voice commands without a list name use the default of the user's default workspace (`ShoppingList::defaultFor()`, `app/Actions/Voice/ResolveShoppingList.php`).

## Recurring shopping lists
`ShoppingListSchedule` is a `Schedulable`: each run is one list's deadline (`due_at`, unique per schedule). `ShoppingListScheduler::reachDeadline()` closes the open list when `closes_automatically`, otherwise leaves it overdue; the next list waits when `waits_for_close`. Completing a list archives it and makes the next one at once, seeded by `next_list_content` (`pending`, `all`, `template_and_pending`, `empty`) and inheriting the default flag.

## Tests
Seed `LookupSeeder`, `travelTo()` a fixed UTC instant, users with `timezone => 'America/Sao_Paulo'`, and drive the scheduler with `$this->artisan('recurrences:dispatch')`. See `ReminderScheduledSlotTest.php`, `ShoppingListScheduleTest.php`, `ShoppingListRealtimeTest.php`.
