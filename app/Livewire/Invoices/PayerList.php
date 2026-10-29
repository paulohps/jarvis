<?php

namespace App\Livewire\Invoices;

use App\Actions\Invoices\CreatePayer;
use App\Actions\Invoices\DeletePayer;
use App\Actions\Invoices\UpdatePayer;
use App\Models\Payer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The payers of the invoices module (US-6.1): who invoices are billed to,
 * listed by name, with the form that adds or edits one in a sheet. A payer
 * an invoice recurrence uses cannot be deleted; the reason is shown above
 * the list.
 *
 * Only the payers of the user's workspaces with the module are listed; a
 * user in several picks the workspace of a new payer (their default one
 * preselected) and sees each payer's workspace. The workspace of an
 * existing payer does not change.
 *
 * @property-read Collection<int, Payer> $payers
 */
#[Layout('layouts::app-shell')]
class PayerList extends Component
{
    use InteractsWithInvoiceWorkspaces;

    public bool $showForm = false;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $address = '';

    /**
     * The workspace (friendly id) of a new payer.
     */
    public string $workspace = '';

    public ?string $status = null;

    /**
     * @return Collection<int, Payer>
     */
    #[Computed]
    public function payers(): Collection
    {
        return Payer::query()
            ->visibleTo($this->actor())
            ->with('workspace')
            ->withCount('invoiceRecurrences')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->workspace = $this->defaultWorkspaceId();
        $this->showForm = true;
    }

    public function edit(string $payerId): void
    {
        $payer = $this->findPayer($payerId);

        $this->resetForm();
        $this->editingId = $payer->friendly_id;
        $this->name = $payer->name;
        $this->address = $payer->address;
        $this->showForm = true;
    }

    public function save(CreatePayer $createPayer, UpdatePayer $updatePayer): void
    {
        $input = ['name' => $this->name, 'address' => $this->address];

        $payer = $this->editingId === null
            ? $createPayer($this->actor(), [...$input, 'workspace' => $this->showsWorkspaces() ? $this->workspace : null])
            : $updatePayer($this->actor(), $this->findPayer($this->editingId), $input);

        $this->status = __('Payer :name saved.', ['name' => $payer->name]);
        $this->resetForm();
        unset($this->payers);
    }

    public function delete(string $payerId, DeletePayer $deletePayer): void
    {
        $payer = $this->findPayer($payerId);

        $deletePayer($this->actor(), $payer);

        $this->status = __('Payer :name deleted.', ['name' => $payer->name]);
        unset($this->payers);
    }

    public function render(): View
    {
        return view('livewire.invoices.payer-list')->title(__('Payers'));
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'address', 'workspace');
        $this->resetValidation();
    }

    private function findPayer(string $payerId): Payer
    {
        return Payer::query()->visibleTo($this->actor())->where('friendly_id', $payerId)->firstOrFail();
    }
}
