<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\NotificationType;

/**
 * A recurrence in review mode issued an invoice that waits for approval
 * (US-6.6, US-5.4).
 */
final class InvoicePendingReview extends InvoiceNotification
{
    public static function key(): string
    {
        return NotificationType::INVOICE_PENDING_REVIEW;
    }

    public static function label(): string
    {
        return __('Invoice pending review');
    }

    public static function description(): string
    {
        return __('Tells that an invoice waits for review before it is sent.');
    }

    protected static function wording(string $locale): array
    {
        return match ($locale) {
            Locale::PT_BR => [
                'title' => 'Invoice {% invoice.number %} aguardando revisão',
                'sentence' => 'A invoice {% invoice.number %} para {% payer.name %} ({% invoice.amount %} {% invoice.currency %}) está pronta e aguarda sua revisão antes do envio.',
                'action' => 'Revisar a invoice',
            ],
            default => [
                'title' => 'Invoice {% invoice.number %} waits for review',
                'sentence' => 'The invoice {% invoice.number %} to {% payer.name %} ({% invoice.amount %} {% invoice.currency %}) is ready and waits for your review before it is sent.',
                'action' => 'Review the invoice',
            ],
        };
    }
}
