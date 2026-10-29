<?php

use App\Models\InvoiceDateRule;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use App\Models\User;
use App\Services\Invoices\InvoiceDateCalculator;
use Carbon\CarbonImmutable;
use Database\Seeders\LookupSeeder;

beforeEach(function () {
    $this->seed(LookupSeeder::class);

    $this->owner = User::factory()->create(['timezone' => 'America/Sao_Paulo']);

    $this->recurrenceWith = fn (string $dateRule, ?int $dateDay, string $dueRule, ?int $dueValue): InvoiceRecurrence => InvoiceRecurrence::factory()->create([
        'created_by_id' => $this->owner->id,
        'invoice_date_rule_id' => InvoiceDateRule::idFor($dateRule),
        'invoice_date_day' => $dateDay,
        'invoice_due_date_rule_id' => InvoiceDueDateRule::idFor($dueRule),
        'due_date_value' => $dueValue,
    ]);

    $this->datesAt = fn (InvoiceRecurrence $recurrence, string $runAt): array => (function () use ($recurrence, $runAt): array {
        $dates = InvoiceDateCalculator::for($recurrence, CarbonImmutable::parse($runAt, 'UTC'));

        return [$dates->issueDate->format('d/m/Y'), $dates->dueDate->format('d/m/Y')];
    })();
});

test('execution day and last day of issue month match the sample invoice', function () {
    $recurrence = ($this->recurrenceWith)(InvoiceDateRule::EXECUTION_DAY, null, InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH, null);

    // Monthly on day 1 at 08:00 São Paulo = 11:00 UTC.
    expect(($this->datesAt)($recurrence, '2026-09-01 11:00'))->toBe(['01/09/2026', '30/09/2026'])
        ->and(($this->datesAt)($recurrence, '2026-10-01 11:00'))->toBe(['01/10/2026', '31/10/2026'])
        ->and(($this->datesAt)($recurrence, '2028-02-10 11:00'))->toBe(['10/02/2028', '29/02/2028']);
});

test('day of current month clamps to month end', function () {
    $recurrence = ($this->recurrenceWith)(InvoiceDateRule::DAY_OF_CURRENT_MONTH, 31, InvoiceDueDateRule::DAYS_AFTER_ISSUE, 0);

    expect(($this->datesAt)($recurrence, '2026-02-05 12:00'))->toBe(['28/02/2026', '28/02/2026'])
        ->and(($this->datesAt)($recurrence, '2026-04-30 12:00'))->toBe(['30/04/2026', '30/04/2026'])
        ->and(($this->datesAt)($recurrence, '2026-10-01 12:00'))->toBe(['31/10/2026', '31/10/2026']);

    $recurrence->update(['invoice_date_day' => 5]);

    // Any day of the run's month issues on the 5th, even after it.
    expect(($this->datesAt)($recurrence->fresh(), '2026-10-20 12:00'))->toBe(['05/10/2026', '05/10/2026']);
});

test('days after issue adds n days', function () {
    $recurrence = ($this->recurrenceWith)(InvoiceDateRule::EXECUTION_DAY, null, InvoiceDueDateRule::DAYS_AFTER_ISSUE, 10);

    expect(($this->datesAt)($recurrence, '2026-10-01 11:00'))->toBe(['01/10/2026', '11/10/2026'])
        ->and(($this->datesAt)($recurrence, '2026-12-25 11:00'))->toBe(['25/12/2026', '04/01/2027']);

    $recurrence->update(['due_date_value' => 365]);

    expect(($this->datesAt)($recurrence->fresh(), '2026-10-01 11:00'))->toBe(['01/10/2026', '01/10/2027']);
});

test('day of next month clamps to month end', function () {
    $recurrence = ($this->recurrenceWith)(InvoiceDateRule::EXECUTION_DAY, null, InvoiceDueDateRule::DAY_OF_NEXT_MONTH, 31);

    expect(($this->datesAt)($recurrence, '2026-01-31 12:00'))->toBe(['31/01/2026', '28/02/2026'])
        ->and(($this->datesAt)($recurrence, '2026-10-01 12:00'))->toBe(['01/10/2026', '30/11/2026'])
        ->and(($this->datesAt)($recurrence, '2026-12-15 12:00'))->toBe(['15/12/2026', '31/01/2027']);

    $recurrence->update(['due_date_value' => 10]);

    expect(($this->datesAt)($recurrence->fresh(), '2026-10-01 12:00'))->toBe(['01/10/2026', '10/11/2026']);
});

test('issue date uses owner timezone at run time', function () {
    $recurrence = ($this->recurrenceWith)(InvoiceDateRule::EXECUTION_DAY, null, InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH, null);

    // 01:00 UTC on October 1st is still September 30th in São Paulo.
    expect(($this->datesAt)($recurrence, '2026-10-01 01:00'))->toBe(['30/09/2026', '30/09/2026']);

    // The owner's timezone when the run happens counts, not the one at save time.
    $this->owner->update(['timezone' => 'Asia/Tokyo']);

    expect(($this->datesAt)($recurrence->fresh(), '2026-09-30 16:00'))->toBe(['01/10/2026', '31/10/2026']);
});
