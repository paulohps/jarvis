<?php

namespace App\Services\Agenda\Sources;

use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceStatus;
use App\Models\Module;
use App\Models\User;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\CalendarSource;
use App\Services\Agenda\ProjectsRuns;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The invoices on the agenda: the ones sent, when they were sent; the ones
 * not sent yet (draft, waiting for review or failed), on their issue date;
 * and the coming runs of the invoice recurrences, when the next ones will
 * be generated. Cancelled invoices are left out. Only those of the
 * reader's workspaces with the invoices module, each entry naming its
 * workspace.
 */
final readonly class InvoiceCalendarSource implements CalendarSource
{
    use ProjectsRuns;

    public function __construct(private RecurrenceCalculator $calculator) {}

    public function keys(): array
    {
        return [CalendarFeed::INVOICES];
    }

    public function isAvailableTo(User $reader): bool
    {
        return $reader->hasModule(Module::INVOICES);
    }

    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array
    {
        $timezone = $reader->timezone;
        $fromDate = $from->setTimezone($timezone)->toDateString();
        $toDate = $to->setTimezone($timezone)->toDateString();

        $invoices = Invoice::query()
            ->visibleTo($reader)
            ->whereRelation('status', 'slug', '!=', InvoiceStatus::CANCELLED)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('sent_at', '>=', $from)->where('sent_at', '<', $to))
                ->orWhere(fn (Builder $query) => $query->whereNull('sent_at')->where('issue_date', '>=', $fromDate)->where('issue_date', '<', $toDate)))
            ->with(['status', 'workspace'])
            ->get();

        $entries = $invoices->map(fn (Invoice $invoice): CalendarEntry => new CalendarEntry(
            key: $invoice->friendly_id,
            source: CalendarFeed::INVOICES,
            title: __('Invoice :number · :payer', ['number' => $invoice->number, 'payer' => $invoice->payer_name]),
            startsAt: $invoice->sent_at !== null
                ? CarbonImmutable::instance($invoice->sent_at)
                : CarbonImmutable::parse($invoice->issue_date->toDateString().' 12:00', $timezone)->utc(),
            isAllDay: $invoice->sent_at === null,
            subtitle: $invoice->sent_at !== null ? __('Sent') : __(ucfirst(str_replace('_', ' ', $invoice->status->slug))),
            url: route('invoices.history.show', $invoice),
            workspace: $invoice->workspace,
        ))->all();

        $recurrences = InvoiceRecurrence::query()
            ->visibleTo($reader)
            ->whereHas('recurrence', fn (Builder $query) => $query->where('is_paused', false)->whereNotNull('next_run_at'))
            ->with(['recurrence.frequency', 'recurrence.weekdays', 'payer', 'workspace'])
            ->get();

        foreach ($recurrences as $invoiceRecurrence) {
            foreach ($this->runsBetween($this->calculator, $invoiceRecurrence->recurrence, $from, $to) as $run) {
                $entries[] = new CalendarEntry(
                    key: "{$invoiceRecurrence->friendly_id}@{$run->getTimestamp()}",
                    source: CalendarFeed::INVOICES,
                    title: __('Invoice: :name', ['name' => $invoiceRecurrence->name]),
                    startsAt: $run,
                    subtitle: $invoiceRecurrence->payer->name,
                    url: route('invoices.recurrences.edit', $invoiceRecurrence),
                    isProjected: true,
                    workspace: $invoiceRecurrence->workspace,
                );
            }
        }

        return array_values($entries);
    }
}
