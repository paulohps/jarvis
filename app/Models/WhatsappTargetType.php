<?php

namespace App\Models;

/**
 * What a WhatsApp target is: a contact (number) or a group (JID).
 */
class WhatsappTargetType extends Lookup
{
    public const string CONTACT = 'contact';

    public const string GROUP = 'group';
}
