---
name: jarvis-recurring-job
description: Use when making a model run something on a schedule (once, daily, weekly, monthly or cron) through the shared recurrences engine — owner model, slot idempotency, timezone, queue, delivery history and time-travel tests.
---

# Making something recurring

Jarvis has one scheduler: `recurrences:dispatch` (every minute, `routes/console.php`) finds due `recurrences` rows and dispatches `RunScheduledSlot` to the owner's queue, which calls `$owner->runScheduledSlot($slot)`. You never write a new cron/command for a schedule; you make a model an owner. Read `.ai/rules/recurrences-and-scheduler.md` first.

Reference owners, simplest first: `app/Models/ShoppingListSchedule.php`, `app/Models/Reminder.php`, `app/Models/ChatScheduledMessage.php` (+ `app/Jobs/SendChatScheduledSlot.php`, `chat_scheduled_deliveries`), `app/Models/InvoiceRecurrence.php` (period-key idempotency).

## 1. The owner model

```php
class PlantWatering extends Model implements Schedulable
{
    use BelongsToWorkspace, HasFactory, HasFriendlyId, HasRecurrence, SoftDeletes;

    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        if ($this->trashed()) {
            return;
        }

        PerformPlantWatering::dispatch($this, $slot->utc()); // or do small work inline
    }

    public function ownerTimezone(): string
    {
        return $this->createdBy->timezone;   // the rule's owner, never config('app.timezone')
    }

    public function scheduledSlotQueue(): string
    {
        return 'default';                    // must be a queue Horizon serves
    }
}
```

- `HasRecurrence` gives `recurrence()` (morphOne; the unique `(schedulable_type, schedulable_id)` allows one rule per owner).
- `SoftDeletes` matters: `Recurrence::due()` skips soft-deleted owners via `whereHasMorph`. Still re-check `trashed()` / cancelled / inactive state inside `runScheduledSlot()` — the job may have been queued before the change.
- `ownerTimezone()` is copied into `recurrences.timezone` at save time; the wall-clock rule is read there and `$slot` is always UTC.

## 2. Register the morph alias

Add it to `Relation::enforceMorphMap([...])` in `app/Providers/AppServiceProvider.php` (`'plant_watering' => PlantWatering::class`). Without it, saving the recurrence and the dispatcher's `whereHasMorph('schedulable', '*')` throw.

## 3. Queue

Use an existing queue (`default`, `notifications`, `invoices`, `whatsapp`, `chats`). A new queue needs a supervisor in `config/horizon.php` under `defaults` and both `environments.production` / `environments.local`, with a `timeout` that fits (PDF/AI work: 120). Otherwise jobs sit in Redis forever.

## 4. Delivery history and the idempotency key

`$slot` (the UTC instant the run was due) is the idempotency key: retries and a crashed dispatcher can deliver the same slot twice. Make a second run a no-op:

- a history table, one row per occurrence, unique on the slot:

```php
Schema::create('plant_waterings_deliveries', function (Blueprint $table) {
    $table->id();
    $table->friendlyId();
    $table->foreignId('plant_watering_id')->nullable()->constrained()->nullOnDelete();
    $table->timestamp('slot_at');
    $table->foreignId('delivery_status_id')->constrained();   // DeliveryStatus: pending/sent/failed
    $table->string('error_message', 500)->nullable();
    $table->unsignedSmallInteger('attempts')->default(0);
    $table->timestamps();
    $table->unique(['plant_watering_id', 'slot_at']);
});
```

- claim before working: return the existing row for `(owner, slot)`; otherwise `create()` and on `UniqueConstraintViolationException` return the row the other worker created (pattern: `app/Actions/Whatsapp/SendWhatsAppMessage.php`). On PostgreSQL a unique violation aborts the surrounding transaction, so catch it **outside** `DB::transaction()` (see `GenerateInvoice`) or don't wrap the insert.
- alternatives already used: a natural unique (`reminder_occurrences (reminder_id, due_at)` + `firstOrCreate`), a period key (`invoices (invoice_recurrence_id, period_key)`), or a conditional update claim (`whereNull('notified_at')->update(...) === 1`) before notifying.
- keep history rows when the owner is deleted (`nullOnDelete`) and record failures with the reason; the user-facing "sending history" screens read these rows.

## 5. Saving the schedule

In the Action that creates/updates the owner (one transaction for owner + rule):

```php
return DB::transaction(function () use ($saveRecurrence, $owner, $validated) {
    $owner->save();

    try {
        $recurrence = $saveRecurrence($owner, $validated['recurrence']);
    } catch (ValidationException $e) {
        throw ValidationException::withMessages(
            collect($e->errors())->mapWithKeys(fn ($m, $f) => ["recurrence.{$f}" => $m])->all()
        );
    }

    return $owner;
});
```

- `SaveRecurrence` input: `frequency` (`once|daily|weekly|monthly|custom_cron`), `time_of_day` (`H:i`), `weekdays` (slugs), `day_of_month` / `is_last_day_of_month`, `cron_expression`, `starts_at`, `ends_at`. Offset-less dates are read in the owner's timezone. `once` must be in the future.
- If the feature makes no sense without a future run, refuse `next_run_at === null` (see `SavesShoppingListSchedule`).
- Livewire: `public RecurrencePicker $recurrence;`, `$this->recurrence->load($timezone, $owner->recurrence)`, `<x-ui.recurrence-picker :form="$recurrence" />`, `$this->recurrence->save($owner)`; its preview uses `RecurrenceCalculator::nextRuns()`.
- API: accept the same `recurrence` object; document it in `docs/api/v1.yaml`.

## 6. Pause, resume, delete

Use `PauseRecurrence` / `ResumeRecurrence` (resume recomputes from now: missed runs are skipped). On delete, soft-delete the owner and pause its rule (`DeleteInvoiceRecurrence`). Never set `next_run_at` by hand outside `SaveRecurrence`, `ResumeRecurrence` and the dispatcher.

## 7. Notify and show on the agenda (optional)

- Notify through `NotificationCenter::dispatch()` after the claim, so it fires once (skill `jarvis-notification-type`).
- To show upcoming runs on the agenda, add a `CalendarSource` in `app/Services/Agenda/Sources/` using the `ProjectsRuns` trait (future runs only, max 400 per window) and register it in `CalendarFeed`.

## 8. Tests (`tests/Feature/<Name>ScheduledSlotTest.php`)

```php
beforeEach(function () {
    $this->seed(LookupSeeder::class);   // frequencies and weekdays are lookup rows
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:59:30', 'UTC'));
    $this->owner = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
});

test('a daily run fires once per slot', function () {
    $watering = app(SavePlantWatering::class)($this->owner, [
        'recurrence' => ['frequency' => 'daily', 'time_of_day' => '09:00'],   // 12:00 UTC
    ]);

    $this->artisan('recurrences:dispatch')->assertSuccessful();      // not due yet
    expect($watering->deliveries()->count())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:10', 'UTC'));
    $this->artisan('recurrences:dispatch')->assertSuccessful();
    $this->artisan('recurrences:dispatch')->assertSuccessful();      // same minute again

    expect($watering->deliveries()->sole()->slot_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00')
        ->and($watering->recurrence->fresh()->next_run_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');
});
```

Also cover:

- the job run twice for the same slot (`(new RunScheduledSlot($recurrence, $slot))->handle()` twice) creates one delivery;
- `Queue::fake()` + `Queue::assertPushedOn('<queue>', RunScheduledSlot::class, …)` for the queue;
- a soft-deleted / cancelled owner runs nothing; pause skips, resume does not replay missed runs;
- downtime: travel several days ahead, one dispatch → one catch-up slot;
- the owner's timezone and a DST boundary if the feature depends on wall-clock time (`tests/Feature/RecurrenceCalculatorTest.php` has examples).

Run `vendor/bin/sail artisan test --compact --filter=<Name>`, `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse`.

## Checklist

- [ ] Model implements `Schedulable`, uses `HasRecurrence` + `SoftDeletes`; guards trashed/cancelled in `runScheduledSlot()`
- [ ] `ownerTimezone()` returns the rule owner's timezone; `$slot` treated as UTC
- [ ] Morph alias in `AppServiceProvider::configureMorphMap()`
- [ ] Queue exists in `config/horizon.php` (all environments) with a fitting timeout
- [ ] History/delivery table unique on `(owner, slot)`; claim before side effects; unique violations caught outside transactions
- [ ] Save Action wraps owner + `SaveRecurrence` in one transaction, errors under `recurrence.*`
- [ ] Pause/resume/delete via the recurrence Actions; no manual `next_run_at` writes
- [ ] Optional: notification after claim; `CalendarSource` for the agenda; API + `docs/api/v1.yaml`
- [ ] Tests with `LookupSeeder`, `travelTo`, `recurrences:dispatch` twice, retry idempotency, timezone
