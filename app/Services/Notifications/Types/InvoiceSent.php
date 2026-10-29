<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\NotificationType;

/**
 * An invoice was e-mailed to its recipients (US-6.5, US-5.4).
 */
final class InvoiceSent extends InvoiceNotification
{
    public static function key(): string
    {
        return NotificationType::INVOICE_SENT;
    }

    public static function label(): string
    {
        return __('Invoice sent');
    }

    public static function description(): string
    {
        return __('Tells that an invoice was e-mailed to its recipients.');
    }

    protected static function wording(string $locale): array
    {
        return match ($locale) {
            Locale::PT_BR => [
                'title' => 'Invoice {% invoice.number %} enviada',
                'sentence' => 'A invoice {% invoice.number %} para {% payer.name %} ({% invoice.amount %} {% invoice.currency %}) foi enviada.',
                'action' => 'Abrir a invoice',
            ],
            default => [
                'title' => 'Invoice {% invoice.number %} sent',
                'sentence' => 'The invoice {% invoice.number %} to {% payer.name %} ({% invoice.amount %} {% invoice.currency %}) was sent.',
                'action' => 'Open the invoice',
            ],
        };
    }
}
