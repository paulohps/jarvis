<?php

namespace App\Services\Invoices;

use App\Models\InvoiceDateRule;
use App\Models\InvoiceDueDateRule;
use App\Models\InvoiceRecurrence;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The dynamic date rules of an invoice recurrence (US-6.3; Key Concept
 * "Dynamic date rule"): the issue and due dates of the run at `$runAt`.
 *
 * The run instant is read on the calendar of the recurrence's owner at run
 * time, so a run at 01:00 UTC on October 1st issues on September 30th in
 * São Paulo. Issue date rules: `execution_day` (that day) or
 * `day_of_current_month` (day X of that month). Due date rules:
 * `days_after_issue` (the issue date plus N days, 0–365),
 * `day_of_next_month` (day X of the month after the issue) or
 * `last_day_of_issue_month`. A day past the end of a shorter month falls on
 * its last day.
 */
final class InvoiceDateCalculator
{
    public static function for(InvoiceRecurrence $invoiceRecurrence, CarbonImmutable $runAt): InvoiceDates
    {
        $runDay = $runAt->setTimezone($invoiceRecurrence->ownerTimezone())->startOfDay();

        $issueDate = match ($invoiceRecurrence->dateRule->slug) {
            InvoiceDateRule::EXECUTION_DAY => $runDay,
            InvoiceDateRule::DAY_OF_CURRENT_MONTH => self::dayOfMonth($runDay, self::requiredValue($invoiceRecurrence->invoice_date_day, 'invoice_date_day')),
            default => throw new InvalidArgumentException("Unknown invoice date rule [{$invoiceRecurrence->dateRule->slug}]."),
        };

        $dueDate = match ($invoiceRecurrence->dueDateRule->slug) {
            InvoiceDueDateRule::DAYS_AFTER_ISSUE => $issueDate->addDays(self::requiredValue($invoiceRecurrence->due_date_value, 'due_date_value')),
            InvoiceDueDateRule::DAY_OF_NEXT_MONTH => self::dayOfMonth(
                $issueDate->startOfMonth()->addMonthNoOverflow(),
                self::requiredValue($invoiceRecurrence->due_date_value, 'due_date_value'),
            ),
            InvoiceDueDateRule::LAST_DAY_OF_ISSUE_MONTH => $issueDate->endOfMonth()->startOfDay(),
            default => throw new InvalidArgumentException("Unknown invoice due date rule [{$invoiceRecurrence->dueDateRule->slug}]."),
        };

        return new InvoiceDates($issueDate, $dueDate);
    }

    /**
     * Day `$day` of the month of `$date`, or its last day when the month is
     * shorter.
     */
    private static function dayOfMonth(CarbonImmutable $date, int $day): CarbonImmutable
    {
        return $date->setDay(min(max($day, 1), $date->daysInMonth))->startOfDay();
    }

    private static function requiredValue(?int $value, string $column): int
    {
        if ($value === null) {
            throw new InvalidArgumentException("The invoice recurrence has no [{$column}] for its rule.");
        }

        return $value;
    }
}
