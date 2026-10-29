<?php

namespace App\Models;

/**
 * What sent a WhatsApp delivery.
 */
class WhatsappDeliverySource extends Lookup
{
    public const string SCHEDULED = 'scheduled';

    public const string MANUAL = 'manual';

    public const string NOTIFICATION = 'notification';

    public const string VOICE = 'voice';

    public const string CHAT = 'chat';
}
