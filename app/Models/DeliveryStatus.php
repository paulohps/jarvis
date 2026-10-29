<?php

namespace App\Models;

/**
 * The state of one delivery attempt (invoice e-mail or WhatsApp message).
 */
class DeliveryStatus extends Lookup
{
    public const string PENDING = 'pending';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';
}
