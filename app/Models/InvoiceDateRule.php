<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * How an invoice recurrence picks the issue date at each run. A rule that
 * `requires_value` reads the day from `invoice_recurrences.invoice_date_day`.
 *
 * @property bool $requires_value
 */
#[Fillable(['slug', 'name', 'requires_value'])]
class InvoiceDateRule extends Lookup
{
    public const string EXECUTION_DAY = 'execution_day';

    public const string DAY_OF_CURRENT_MONTH = 'day_of_current_month';

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
