<?php

namespace App\Livewire\Invoices;

use App\Models\InvoiceRecurrence;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The home of the invoices module (US-6.3): the invoice recurrences, each
 * with its payer, amount, status (active or paused) and next run in the
 * reader's timezone, plus the way to the payers and issuers. Only the
 * recurrences of the reader's workspaces with the module, each marked with
 * its workspace when they have several.
 *
 * @property-read Collection<int, InvoiceRecurrence> $recurrences
 */
#[Layout('layouts::app-shell')]
class InvoiceRecurrenceList extends Component
{
    use InteractsWithInvoiceWorkspaces;

    /**
     * @return Collection<int, InvoiceRecurrence>
     */
    #[Computed]
    public function recurrences(): Collection
    {
        return InvoiceRecurrence::query()
            ->visibleTo($this->actor())
            ->with(['payer', 'currency', 'recurrence', 'workspace'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.invoices.invoice-recurrence-list')->title(__('Invoices'));
    }
}
