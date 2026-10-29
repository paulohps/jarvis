<?php

namespace App\Services\Templates;

/**
 * How a rendered template is shown: an e-mail or notification body keeps
 * light, sanitized formatting; a title or a WhatsApp message is plain text.
 */
enum TemplateFormat: string
{
    case Html = 'html';

    case Text = 'text';
}
