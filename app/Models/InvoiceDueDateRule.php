<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * How an invoice recurrence picks the due date from the issue date. A rule
 * that `requires_value` reads it from `invoice_recurrences.due_date_value`.
 *
 * @property bool $requires_value
 */
#[Fillable(['slug', 'name', 'requires_value'])]
class InvoiceDueDateRule extends Lookup
{
    public const string DAYS_AFTER_ISSUE = 'days_after_issue';

    public const string DAY_OF_NEXT_MONTH = 'day_of_next_month';

    public const string LAST_DAY_OF_ISSUE_MONTH = 'last_day_of_issue_month';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_value' => 'boolean',
        ];
    }
}
