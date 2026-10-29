<?php

namespace App\Livewire\Invoices;

use App\Actions\Invoices\ApproveInvoice;
use App\Actions\Invoices\CancelInvoice;
use App\Actions\Invoices\ResendInvoice;
use App\Actions\Invoices\SaveInvoiceRecurrence;
use App\Actions\Invoices\UpdatePendingInvoice;
use App\Models\Invoice;
use App\Models\InvoiceDelivery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One issued invoice (US-6.6): its number, status, payer, issuer, dates,
 * amount, description, recipients and delivery attempts. While it waits to
 * be sent (`draft` / `pending_review`) its description, dates, amount and
 * recipients can be edited (the PDF is rendered again on save), and it can
 * be approved and sent, or discarded (`cancelled`, keeping its number). A
 * sent or failed invoice can be sent again with the same number and PDF.
 * An invoice of a workspace the reader does not see is not found.
 *
 * @property-read Collection<int, InvoiceDelivery> $deliveries
 */
#[Layout('layouts::app-shell')]
class InvoiceReview extends Component
{
    use InteractsWithInvoiceWorkspaces;

    public Invoice $invoice;

    public string $service_description = '';

    public string $issue_date = '';

    public string $due_date = '';

    public string $amount = '';

    /**
     * @var list<string>
     */
    public array $recipients = [''];

    public ?string $status = null;

    public function mount(Invoice $invoice): void
    {
        abort_unless($invoice->isVisibleTo($this->actor()), 404);

        $this->invoice = $invoice;
        $this->fillForm();
    }

    /**
     * @return Collection<int, InvoiceDelivery>
     */
    #[Computed]
    public function deliveries(): Collection
    {
        return $this->invoice->deliveries()->with(['status', 'triggeredBy'])->latest('id')->get();
    }

    public function addRecipient(): void
    {
        if (count($this->recipients) < SaveInvoiceRecurrence::MAX_RECIPIENTS) {
            $this->recipients[] = '';
        }
    }

    public function removeRecipient(int $index): void
    {
        $recipients = $this->recipients;
        unset($recipients[$index]);
        $this->recipients = array_values($recipients) ?: [''];
    }

    public function save(UpdatePendingInvoice $updatePendingInvoice): void
    {
        $this->invoice = $updatePendingInvoice($this->actor(), $this->invoice, [
            'service_description' => $this->service_description,
            'issue_date' => $this->issue_date,
            'due_date' => $this->due_date,
            'amount' => $this->amount,
            'recipients' => $this->recipients,
        ]);

        $this->status = __('Invoice :number saved. Its PDF was updated.', ['number' => $this->invoice->number]);
        $this->fillForm();
    }

    public function approve(ApproveInvoice $approveInvoice): void
    {
        $approveInvoice($this->actor(), $this->invoice);

        $this->status = __('Invoice :number approved and sent to its recipients.', ['number' => $this->invoice->number]);
        $this->refreshInvoice();
    }

    public function cancel(CancelInvoice $cancelInvoice): void
    {
        $cancelInvoice($this->actor(), $this->invoice);

        $this->status = __('Invoice :number cancelled. Its number is not reused.', ['number' => $this->invoice->number]);
        $this->refreshInvoice();
    }

    public function resend(ResendInvoice $resendInvoice): void
    {
        $resendInvoice($this->actor(), $this->invoice);

        $this->status = __('Invoice :number sent again to its recipients.', ['number' => $this->invoice->number]);
        $this->refreshInvoice();
    }

    public function render(): View
    {
        return view('livewire.invoices.invoice-review')->title(__('Invoice :number', ['number' => $this->invoice->number]));
    }

    private function refreshInvoice(): void
    {
        $this->invoice->refresh();
        unset($this->deliveries);
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->invoice->loadMissing(['status', 'currency', 'recurrence', 'recipients', 'workspace']);

        $this->service_description = $this->invoice->service_description;
        $this->issue_date = $this->invoice->issue_date->toDateString();
        $this->due_date = $this->invoice->due_date->toDateString();
        $this->amount = $this->invoice->amount;
        $this->recipients = array_values($this->invoice->recipients()->orderBy('id')->pluck('email')->all()) ?: [''];
        $this->resetValidation();
    }
}
