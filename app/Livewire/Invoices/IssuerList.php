<?php

namespace App\Livewire\Invoices;

use App\Actions\Invoices\CreateIssuer;
use App\Actions\Invoices\DeleteIssuer;
use App\Actions\Invoices\UpdateIssuer;
use App\Models\Issuer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The issuers of the invoices module (US-6.2): who issues invoices, with
 * the "Receiver information" each prints, and the form that adds or edits
 * one in a sheet. An issuer an invoice recurrence uses cannot be deleted.
 *
 * The fields are named after the Action's input, so its errors show next to
 * them.
 *
 * Only the issuers of the user's workspaces with the module are listed; a
 * user in several picks the workspace of a new issuer (their default one
 * preselected) and sees each issuer's workspace. The workspace of an
 * existing issuer does not change.
 *
 * @property-read Collection<int, Issuer> $issuers
 */
#[Layout('layouts::app-shell')]
class IssuerList extends Component
{
    use InteractsWithInvoiceWorkspaces;

    public const array FIELDS = ['name', 'email', 'postal_code', 'street', 'district', 'city', 'state'];

    public bool $showForm = false;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $postal_code = '';

    public string $street = '';

    public string $district = '';

    public string $city = '';

    public string $state = '';

    /**
     * The workspace (friendly id) of a new issuer.
     */
    public string $workspace = '';

    public ?string $status = null;

    /**
     * @return Collection<int, Issuer>
     */
    #[Computed]
    public function issuers(): Collection
    {
        return Issuer::query()->visibleTo($this->actor())->with('workspace')->orderBy('name')->orderBy('id')->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->workspace = $this->defaultWorkspaceId();
        $this->showForm = true;
    }

    public function edit(string $issuerId): void
    {
        $issuer = $this->findIssuer($issuerId);

        $this->resetForm();
        $this->editingId = $issuer->friendly_id;
        $this->fill($issuer->only(self::FIELDS));
        $this->showForm = true;
    }

    public function save(CreateIssuer $createIssuer, UpdateIssuer $updateIssuer): void
    {
        $input = $this->only(self::FIELDS);

        $issuer = $this->editingId === null
            ? $createIssuer($this->actor(), [...$input, 'workspace' => $this->showsWorkspaces() ? $this->workspace : null])
            : $updateIssuer($this->actor(), $this->findIssuer($this->editingId), $input);

        $this->status = __('Issuer :name saved.', ['name' => $issuer->name]);
        $this->resetForm();
        unset($this->issuers);
    }

    public function delete(string $issuerId, DeleteIssuer $deleteIssuer): void
    {
        $issuer = $this->findIssuer($issuerId);

        $deleteIssuer($this->actor(), $issuer);

        $this->status = __('Issuer :name deleted.', ['name' => $issuer->name]);
        unset($this->issuers);
    }

    public function render(): View
    {
        return view('livewire.invoices.issuer-list')->title(__('Issuers'));
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'workspace', ...self::FIELDS);
        $this->resetValidation();
    }

    private function findIssuer(string $issuerId): Issuer
    {
        return Issuer::query()->visibleTo($this->actor())->where('friendly_id', $issuerId)->firstOrFail();
    }
}
