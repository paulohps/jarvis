<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\NotificationType;

/**
 * An invoice could not be e-mailed after every attempt (US-6.5, US-5.4).
 */
final class InvoiceFailed extends InvoiceNotification
{
    public static function key(): string
    {
        return NotificationType::INVOICE_FAILED;
    }

    public static function label(): string
    {
        return __('Invoice failed');
    }

    public static function description(): string
    {
        return __('Tells that an invoice could not be e-mailed.');
    }

    protected static function wording(string $locale): array
    {
        return match ($locale) {
            Locale::PT_BR => [
                'title' => 'Falha ao enviar a invoice {% invoice.number %}',
                'sentence' => 'Não foi possível enviar a invoice {% invoice.number %} para {% payer.name %} ({% invoice.amount %} {% invoice.currency %}). Confira os destinatários e reenvie.',
                'action' => 'Abrir a invoice',
            ],
            default => [
                'title' => 'Invoice {% invoice.number %} could not be sent',
                'sentence' => 'The invoice {% invoice.number %} to {% payer.name %} ({% invoice.amount %} {% invoice.currency %}) could not be sent. Check the recipients and send it again.',
                'action' => 'Open the invoice',
            ],
        };
    }
}
