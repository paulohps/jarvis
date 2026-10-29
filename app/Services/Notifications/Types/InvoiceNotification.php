<?php

namespace App\Services\Notifications\Types;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Illuminate\Support\Facades\Route;

/**
 * Base of the invoice notifications (US-5.4): `invoice_sent`,
 * `invoice_failed` and `invoice_pending_review`. They belong to the
 * `invoices` module, so only users with it switched on receive them, on
 * every channel of the center, with the invoice's number, payer, amount,
 * currency and the link to the invoice.
 *
 * A type provides its wording through `wording()`: the title and the
 * sentence of the body in each language; the body of each channel is built
 * around it (light HTML in the app and e-mail, plain text with the link on
 * push and WhatsApp).
 */
abstract class InvoiceNotification extends CenterNotification
{
    public const string INVOICE_ROUTE = 'invoices.history.show';

    public function __construct(public Invoice $invoice) {}

    /**
     * The title and the body sentence in a language (`locales.slug`).
     *
     * @return array{title: string, sentence: string, action: string}
     */
    abstract protected static function wording(string $locale): array;

    public static function module(): ?string
    {
        return Module::INVOICES;
    }

    public static function channels(): array
    {
        return [
            NotificationChannel::DATABASE,
            NotificationChannel::MAIL,
            NotificationChannel::PUSH,
            NotificationChannel::WHATSAPP,
        ];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('invoice.number', 'The invoice number', '202610-001'),
            TemplateVariable::money('invoice.amount', 'The amount, such as 2,000.00', '2000.00'),
            TemplateVariable::text('invoice.currency', 'The currency, written out', 'American dollar'),
            TemplateVariable::text('invoice.url', 'The link to the invoice', 'https://jarvis.example/invoices/history/invc_01'),
            TemplateVariable::text('payer.name', 'Who the invoice is billed to', 'Initech Corp'),
        ]);
    }

    public static function links(): array
    {
        return [
            'invoice' => NotificationLink::make(
                'Open the invoice',
                static fn (array $context, object $notifiable): ?string => $context['invoice']['url'] ?? null,
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        $wording = static::wording($locale);
        $actions = [['link_key' => 'invoice', 'label' => $wording['action']]];

        return match ($channel) {
            NotificationChannel::PUSH => new ChannelDefaults($wording['title'], $wording['sentence'], $actions),
            NotificationChannel::WHATSAPP => new ChannelDefaults($wording['title'], $wording['sentence'].' {% invoice.url %}'),
            default => new ChannelDefaults($wording['title'], "<p>{$wording['sentence']}</p>", $actions),
        };
    }

    public function context(object $notifiable): array
    {
        return [
            'invoice' => [
                'number' => $this->invoice->number,
                'amount' => $this->invoice->amount,
                'currency' => Currency::labelFor($this->invoice->currency->slug, $this->localeFor($notifiable)),
                'url' => self::invoiceUrl($this->invoice),
            ],
            'payer' => ['name' => $this->invoice->payer_name],
        ];
    }

    /**
     * The invoice's screen, or the invoices home until it exists.
     */
    public static function invoiceUrl(Invoice $invoice): string
    {
        return Route::has(self::INVOICE_ROUTE) ? route(self::INVOICE_ROUTE, $invoice) : route('invoices.index');
    }
}
