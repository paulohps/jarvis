<?php

namespace App\Models;

/**
 * Whether an invoice recurrence sends on its own or waits for a review.
 */
class InvoiceDeliveryMode extends Lookup
{
    public const string AUTOMATIC = 'automatic';

    public const string REQUIRES_REVIEW = 'requires_review';
}
