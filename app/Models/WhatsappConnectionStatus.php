<?php

namespace App\Models;

/**
 * The connection state of a WhatsApp account, as the Evolution API reports it.
 */
class WhatsappConnectionStatus extends Lookup
{
    public const string CONNECTING = 'connecting';

    public const string OPEN = 'open';

    public const string CLOSED = 'closed';
}
