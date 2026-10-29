{{--
    The issuers: who issues invoices, each with the "Receiver information"
    it prints, edit and delete (refused while an invoice recurrence uses it).
    The form opens in a sheet. A user with invoices in several workspaces
    sees each issuer's workspace and picks the workspace of a new one.
--}}
@php($showsWorkspaces = $this->showsWorkspaces())
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('invoices.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Invoices') }}
        </a>

        <x-button :text="__('Add issuer')" icon="plus" wire:click="create" data-issuer-create />
    </div>

    @if (filled($status))
        <p role="status" data-issuer-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @error('issuer')
        <p role="alert" data-issuer-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($this->issuers->isEmpty())
        <x-ui.empty-state icon="identification"
                          :title="__('No issuers yet')"
                          :description="__('Add who issues your invoices; their details print under “Receiver information”.')" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->issuers as $issuer)
                <x-ui.list-row wire:key="issuer-{{ $issuer->friendly_id }}"
                               data-issuer="{{ $issuer->friendly_id }}"
                               :title="$issuer->name"
                               :subtitle="$issuer->email"
                               icon="identification">
                    <p class="text-[0.8125rem] text-secondary-label">
                        {{ $issuer->street }} · {{ $issuer->district }} · {{ $issuer->postal_code }} {{ $issuer->city }}, {{ $issuer->state }}
                    </p>

                    @if ($showsWorkspaces)
                        @include('livewire.invoices.partials.workspace-badge', ['workspace' => $issuer->workspace])
                    @endif

                    <x-slot:trailing>
                        <div class="flex shrink-0 items-center gap-1">
                            <x-button.circle icon="pencil-square" flat color="secondary" sm
                                             :title="__('Edit')" wire:click="edit('{{ $issuer->friendly_id }}')" />

                            <x-ui.confirm-dialog :title="__('Delete :name?', ['name' => $issuer->name])"
                                                 :description="__('Invoices already issued keep their copy of the issuer.')"
                                                 :confirm-label="__('Delete')"
                                                 danger
                                                 wire:click="delete('{{ $issuer->friendly_id }}')">
                                <x-slot:trigger>
                                    <x-button.circle icon="trash" flat color="red" sm :title="__('Delete')" />
                                </x-slot:trigger>
                            </x-ui.confirm-dialog>
                        </div>
                    </x-slot:trailing>
                </x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>
    @endif

    <x-modal wire="showForm" :title="$editingId === null ? __('New issuer') : __('Edit issuer')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-issuer-form>
            @if ($editingId === null && $this->showsWorkspaces())
                <x-select.native :label="__('Workspace')" wire:model="workspace" data-issuer-workspace>
                    @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                        <option value="{{ $workspaceId }}" wire:key="workspace-option-{{ $workspaceId }}">{{ $workspaceName }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <x-input :label="__('Name or company name')" wire:model="name" maxlength="255" autocomplete="organization" required />
            <x-input type="email" :label="__('E-mail')" wire:model="email" maxlength="255" autocomplete="email" required />

            <div class="grid gap-5 sm:grid-cols-[10rem_1fr]">
                <x-input :label="__('Postal code')" wire:model="postal_code" maxlength="20" autocomplete="postal-code" required />
                <x-input :label="__('Street and number')" wire:model="street" maxlength="255" autocomplete="address-line1" required />
            </div>

            <x-input :label="__('District')" wire:model="district" maxlength="255" required />

            <div class="grid gap-5 sm:grid-cols-[1fr_8rem]">
                <x-input :label="__('City')" wire:model="city" maxlength="255" autocomplete="address-level2" required />
                <x-input :label="__('State')" wire:model="state" maxlength="50" autocomplete="address-level1" required />
            </div>

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
