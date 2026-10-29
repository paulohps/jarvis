<?php

namespace App\Models;

/**
 * The life cycle of an invoice: `draft` → `pending_review` → `sent` / `failed`, or `cancelled`.
 */
class InvoiceStatus extends Lookup
{
    public const string DRAFT = 'draft';

    public const string PENDING_REVIEW = 'pending_review';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';
}
