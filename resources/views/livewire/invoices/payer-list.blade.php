{{--
    The payers: who invoices are billed to, each with its "Bill to" address,
    edit and delete (refused while an invoice recurrence uses it). The form
    opens in a sheet. A user with invoices in several workspaces sees each
    payer's workspace and picks the workspace of a new one.
--}}
@php($showsWorkspaces = $this->showsWorkspaces())
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('invoices.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Invoices') }}
        </a>

        <x-button :text="__('Add payer')" icon="plus" wire:click="create" data-payer-create />
    </div>

    @if (filled($status))
        <p role="status" data-payer-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @error('payer')
        <p role="alert" data-payer-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($this->payers->isEmpty())
        <x-ui.empty-state icon="building-office"
                          :title="__('No payers yet')"
                          :description="__('Add who you bill to use them in invoice recurrences.')" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->payers as $payer)
                <x-ui.list-row wire:key="payer-{{ $payer->friendly_id }}"
                               data-payer="{{ $payer->friendly_id }}"
                               :title="$payer->name"
                               icon="building-office">
                    <p class="text-[0.8125rem] whitespace-pre-line text-secondary-label">{{ $payer->address }}</p>

                    @if ($showsWorkspaces)
                        @include('livewire.invoices.partials.workspace-badge', ['workspace' => $payer->workspace])
                    @endif

                    <x-slot:trailing>
                        <div class="flex shrink-0 items-center gap-1">
                            <x-button.circle icon="pencil-square" flat color="secondary" sm
                                             :title="__('Edit')" wire:click="edit('{{ $payer->friendly_id }}')" />

                            <x-ui.confirm-dialog :title="__('Delete :name?', ['name' => $payer->name])"
                                                 :description="__('Invoices already issued keep their copy of the payer.')"
                                                 :confirm-label="__('Delete')"
                                                 danger
                                                 wire:click="delete('{{ $payer->friendly_id }}')">
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

    <x-modal wire="showForm" :title="$editingId === null ? __('New payer') : __('Edit payer')">
        <form wire:submit="save" class="flex flex-col gap-5" novalidate data-payer-form>
            @if ($editingId === null && $this->showsWorkspaces())
                <x-select.native :label="__('Workspace')" wire:model="workspace" data-payer-workspace>
                    @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                        <option value="{{ $workspaceId }}" wire:key="workspace-option-{{ $workspaceId }}">{{ $workspaceName }}</option>
                    @endforeach
                </x-select.native>
            @endif

            <x-input :label="__('Name')" wire:model="name" maxlength="255" autocomplete="organization" required />

            <x-textarea :label="__('Address')"
                        :hint="__('As it should appear under “Bill to”, one line per row.')"
                        wire:model="address"
                        rows="4"
                        required />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
            </div>
        </form>
    </x-modal>
</div>
