<?php

namespace App\Services\Templates;

/**
 * What a template variable holds, which decides how its value is written out
 * before it reaches the renderer (TemplateContextBuilder::format()).
 */
enum TemplateVariableType: string
{
    /**
     * Free text, printed as it is.
     */
    case Text = 'text';

    /**
     * A whole or decimal number, printed as it is.
     */
    case Number = 'number';

    /**
     * A calendar day (an invoice date), written out in the reader's language
     * and never shifted to another timezone.
     */
    case Date = 'date';

    /**
     * An instant, written out in the reader's language and timezone.
     */
    case DateTime = 'datetime';

    /**
     * An amount in major units, written with two decimals as invoices print it.
     */
    case Money = 'money';

    /**
     * A flag for `{% if %}`; prints nothing.
     */
    case Boolean = 'boolean';

    /**
     * A list for `{% for %}`, whose items expose their own fields.
     */
    case Collection = 'collection';
}
