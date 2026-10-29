<?php

namespace App\Services\Invoices;

use App\Models\Currency;
use App\Models\Invoice;
use App\Services\Templates\TemplateContextBuilder;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The variables of the invoice e-mail subject and body (US-6.3, US-4.1;
 * Workflow 4), with the sample invoice the preview renders, and the context
 * of a real invoice.
 *
 * The context is written out before it reaches the renderer: the dates as
 * the invoice prints them (`dd/mm/yyyy`), the amount as `2,000.00` and the
 * currency spelled out in the reader's language ("American dollar", "Dólar
 * americano"). `current_month_name` and `current_year` are read on the
 * invoice date, so an e-mail previewed today for next month's run already
 * names next month.
 */
final class InvoiceTemplateSchema extends TemplateSchema
{
    /**
     * How the invoice and its e-mail write a date.
     */
    public const string DATE_FORMAT = 'd/m/Y';

    /**
     * The common variables an invoice e-mail offers.
     */
    private const array COMMON_VARIABLES = ['current_month_name', 'current_year'];

    public function variables(): array
    {
        $common = array_values(array_filter(
            TemplateContextBuilder::commonVariables(),
            static fn (TemplateVariable $variable): bool => in_array($variable->path, self::COMMON_VARIABLES, true),
        ));

        return [
            ...$common,
            TemplateVariable::text('invoice.number', 'The invoice number', '202610-001'),
            TemplateVariable::text('invoice.date', 'The invoice date (dd/mm/yyyy)', '01/10/2026'),
            TemplateVariable::text('invoice.due_date', 'The due date (dd/mm/yyyy)', '31/10/2026'),
            TemplateVariable::money('invoice.amount', 'The amount, such as 2,000.00', '2000.00'),
            TemplateVariable::text('invoice.currency', 'The currency, written out', Currency::labelFor(Currency::USD)),
            TemplateVariable::text('payer.name', 'Who the invoice is billed to', 'Initech Corp'),
            TemplateVariable::text('issuer.name', 'Who issues the invoice', 'Jane Doe Consulting Ltda'),
        ];
    }

    /**
     * The context of an invoice (saved or not) for a reader with the
     * builder's language and timezone.
     *
     * @return array{current_month_name: string, current_year: int, current_date: string, next_month_name: string, invoice: array{number: string, date: string, due_date: string, amount: string, currency: string}, payer: array{name: string}, issuer: array{name: string}}
     */
    public function context(Invoice $invoice, TemplateContextBuilder $builder): array
    {
        return [
            ...$builder->common($this->onReaderCalendar($invoice->issue_date, $builder->timezone)),
            'invoice' => [
                'number' => $invoice->number,
                'date' => $invoice->issue_date->format(self::DATE_FORMAT),
                'due_date' => $invoice->due_date->format(self::DATE_FORMAT),
                'amount' => $builder->formatMoney($invoice->amount),
                'currency' => Currency::labelFor($invoice->currency->slug, $builder->locale),
            ],
            'payer' => ['name' => $invoice->payer_name],
            'issuer' => ['name' => $invoice->issuer_name],
        ];
    }

    /**
     * Midday of a calendar day in the reader's timezone, so turning it into
     * their local time never moves it to the day before.
     */
    private function onReaderCalendar(CarbonInterface $day, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::create($day->year, $day->month, $day->day, 12, 0, 0, $timezone);
    }
}
