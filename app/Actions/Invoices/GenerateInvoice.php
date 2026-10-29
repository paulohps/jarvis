<?php

namespace App\Actions\Invoices;

use App\Actions\Workspaces\ResolvesWorkspace;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryMode;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceRecurrenceRecipient;
use App\Models\InvoiceStatus;
use App\Models\User;
use App\Services\Invoices\InvoiceDateCalculator;
use App\Services\Invoices\InvoiceNumberGenerator;
use App\Services\Invoices\InvoicePdf;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues an invoice (US-6.5, US-6.6; Workflow 8): from an invoice recurrence
 * or, for a one-off invoice (US-6.7), from the same fields given as data.
 *
 * In one transaction it computes the issue and due dates of the run at
 * `$runAt` (InvoiceDateCalculator), reserves the next number of the issue
 * month in the recurrence's workspace (InvoiceNumberGenerator), stores the
 * invoice in that workspace with a snapshot of the payer and issuer, its
 * recipients and the e-mail subject and body templates as they are. The PDF is rendered onto the private disk after
 * the transaction commits, so the Gotenberg call never holds the lock on
 * the month's number sequence; an invoice whose PDF failed keeps its number
 * and gets the PDF on the next call for its period or when it is sent. The
 * initial status follows the delivery mode: `draft` when it is sent
 * automatically, `pending_review` when it waits for approval. Nothing is
 * sent here.
 *
 * A recurrence run with a `$periodKey` is idempotent: the recurrence row is
 * locked and, when an invoice of that period exists, that invoice is
 * returned without creating another or using a number. `wasRecentlyCreated`
 * tells the two apart. Without a period key (manual runs) every call issues
 * a new invoice.
 *
 * The data of a one-off invoice holds the recurrence columns
 * (`workspace_id`, `payer_id`, `issuer_id`, `currency_id`, `amount`, `service_description`,
 * `email_subject`, `email_body`, `invoice_date_rule_id`, `invoice_date_day`,
 * `invoice_due_date_rule_id`, `due_date_value`, `invoice_delivery_mode_id`)
 * and `recipients` (list of e-mails), already validated; the actor is
 * required then, as the dates are read on their calendar. A user asking for
 * an invoice of a recurrence of a workspace they do not see is refused as
 * not found.
 */
final readonly class GenerateInvoice
{
    use AuthorizesInvoicesModule;
    use ResolvesWorkspace;

    /**
     * @param  InvoiceRecurrence|array<string, mixed>  $source
     * @param  CarbonImmutable  $runAt  the run instant the dates are computed from
     * @param  string|null  $periodKey  the recurrence period (`2026-10`), null for a manual run
     * @param  User|null  $actor  who asked for it; null for the scheduler
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException when the actor does not see the recurrence's workspace
     */
    public function __invoke(InvoiceRecurrence|array $source, CarbonImmutable $runAt, ?string $periodKey = null, ?User $actor = null): Invoice
    {
        if ($actor !== null) {
            $this->authorizeInvoices($actor);

            if ($source instanceof InvoiceRecurrence) {
                $this->authorizeWorkspaceRecord($actor, $source);
            }
        }

        $invoiceRecurrence = $source instanceof InvoiceRecurrence ? $source : $this->unsavedRecurrence($source, $actor);
        $isIdempotent = $invoiceRecurrence->exists && $periodKey !== null;

        if ($isIdempotent && ($existing = $this->existing($invoiceRecurrence, $periodKey)) !== null) {
            return $this->withPdf($existing);
        }

        try {
            $invoice = DB::transaction(function () use ($invoiceRecurrence, $runAt, $periodKey, $actor, $isIdempotent): Invoice {
                if ($isIdempotent) {
                    InvoiceRecurrence::query()->withTrashed()->whereKey($invoiceRecurrence->id)->lockForUpdate()->first();

                    if (($existing = $this->existing($invoiceRecurrence, $periodKey)) !== null) {
                        return $existing;
                    }
                }

                return $this->issue($invoiceRecurrence, $runAt, $periodKey, $actor);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $isIdempotent) {
                throw $exception;
            }

            $invoice = $this->existing($invoiceRecurrence, $periodKey) ?? throw $exception;
        }

        return $this->withPdf($invoice);
    }

    /**
     * Renders and stores the PDF of an invoice that has none yet.
     */
    private function withPdf(Invoice $invoice): Invoice
    {
        if ($invoice->pdf_path === null) {
            $invoice->forceFill(['pdf_path' => InvoicePdf::store($invoice)])->save();
        }

        return $invoice;
    }

    private function issue(InvoiceRecurrence $invoiceRecurrence, CarbonImmutable $runAt, ?string $periodKey, ?User $actor): Invoice
    {
        $dates = InvoiceDateCalculator::for($invoiceRecurrence, $runAt);
        $number = InvoiceNumberGenerator::next($invoiceRecurrence->workspace_id, $dates->issueDate);
        [, $sequence] = InvoiceNumberGenerator::parse($number);

        $invoice = $invoiceRecurrence->newInvoice($dates, $number, $sequence);

        if (! $invoiceRecurrence->exists) {
            $invoice->setRelation('recurrence', null);
        }

        $invoice->fill([
            'period_key' => $invoiceRecurrence->exists ? $periodKey : null,
            'invoice_status_id' => InvoiceStatus::idFor(
                $invoiceRecurrence->invoice_delivery_mode_id === InvoiceDeliveryMode::idFor(InvoiceDeliveryMode::REQUIRES_REVIEW)
                    ? InvoiceStatus::PENDING_REVIEW
                    : InvoiceStatus::DRAFT,
            ),
            'created_by_id' => $actor?->id,
        ])->save();

        foreach ($invoiceRecurrence->recipients as $recipient) {
            $invoice->recipients()->create(['email' => $recipient->email]);
        }

        return $invoice;
    }

    private function existing(InvoiceRecurrence $invoiceRecurrence, ?string $periodKey): ?Invoice
    {
        return Invoice::query()
            ->where('invoice_recurrence_id', $invoiceRecurrence->id)
            ->where('period_key', $periodKey)
            ->first();
    }

    /**
     * The fields of a one-off invoice as an unsaved recurrence, so it goes
     * through the same rules and snapshot.
     *
     * @param  array<string, mixed>  $data
     */
    private function unsavedRecurrence(array $data, ?User $actor): InvoiceRecurrence
    {
        if ($actor === null) {
            throw new InvalidArgumentException('A one-off invoice needs the user who issues it.');
        }

        /** @var list<string> $emails */
        $emails = $data['recipients'] ?? [];
        unset($data['recipients']);

        $invoiceRecurrence = new InvoiceRecurrence([...$data, 'created_by_id' => $actor->id]);

        return $invoiceRecurrence
            ->setRelation('createdBy', $actor)
            ->setRelation('recipients', new Collection(array_map(
                static fn (string $email): InvoiceRecurrenceRecipient => new InvoiceRecurrenceRecipient(['email' => $email]),
                $emails,
            )));
    }
}
