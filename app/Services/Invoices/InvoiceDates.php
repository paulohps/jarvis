<?php

namespace App\Services\Invoices;

use Carbon\CarbonImmutable;

/**
 * The issue and due dates of one invoice run: calendar days, at midnight in
 * the timezone of the owner they were computed in.
 */
final readonly class InvoiceDates
{
    public function __construct(
        public CarbonImmutable $issueDate,
        public CarbonImmutable $dueDate,
    ) {}
}
