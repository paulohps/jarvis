{{--
    The invoices home: the invoice recurrences with payer, amount, status and
    next run, and the links to the history, the payers and the issuers. A
    user with invoices in several workspaces sees each recurrence's.
--}}
@php($showsWorkspaces = $this->showsWorkspaces())
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-ui.inset-grouped-list>
        <x-ui.list-row :title="__('Invoice history')" :subtitle="__('Every invoice issued, with its status and PDF.')" icon="document-text"
                       :href="route('invoices.history.index')" wire:navigate data-invoices-link="history" />
    </x-ui.inset-grouped-list>

    <x-ui.inset-grouped-list :header="__('Registers')">
        <x-ui.list-row :title="__('Payers')" :subtitle="__('Who your invoices are billed to.')" icon="building-office"
                       :href="route('invoices.payers.index')" wire:navigate data-invoices-link="payers" />
        <x-ui.list-row :title="__('Issuers')" :subtitle="__('Who issues the invoices.')" icon="identification"
                       :href="route('invoices.issuers.index')" wire:navigate data-invoices-link="issuers" />
    </x-ui.inset-grouped-list>

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[1.25rem] font-semibold text-label">{{ __('Invoice recurrences') }}</h2>

            <x-button :text="__('New recurrence')" icon="plus" :href="route('invoices.recurrences.create')" wire:navigate data-recurrence-create />
        </div>

        @if ($this->recurrences->isEmpty())
            <x-ui.empty-state icon="arrow-path"
                              :title="__('No invoice recurrences yet')"
                              :description="__('A recurrence issues and sends an invoice on its own, on the schedule you choose.')" />
        @else
            <x-ui.inset-grouped-list>
                @foreach ($this->recurrences as $invoiceRecurrence)
                    @php($isPaused = (bool) $invoiceRecurrence->recurrence?->is_paused)
                    <x-ui.list-row wire:key="invoice-recurrence-{{ $invoiceRecurrence->friendly_id }}"
                                   data-invoice-recurrence="{{ $invoiceRecurrence->friendly_id }}"
                                   :title="$invoiceRecurrence->name"
                                   :subtitle="$invoiceRecurrence->payer->name"
                                   :href="route('invoices.recurrences.edit', $invoiceRecurrence)">
                        <p class="text-[0.8125rem] text-secondary-label">
                            <x-ui.money :amount="$invoiceRecurrence->amount" :currency="$invoiceRecurrence->currency" />
                            ·
                            @if ($isPaused || $invoiceRecurrence->recurrence?->next_run_at === null)
                                {{ __('No next run') }}
                            @else
                                {{ __('Next run') }} <x-ui.localized-date :value="$invoiceRecurrence->recurrence->next_run_at" data-invoice-recurrence-next-run />
                            @endif
                        </p>

                        @if ($showsWorkspaces)
                            @include('livewire.invoices.partials.workspace-badge', ['workspace' => $invoiceRecurrence->workspace])
                        @endif

                        <x-slot:trailing>
                            <x-ui.status-badge :status="$isPaused ? 'paused' : 'active'" />
                        </x-slot:trailing>
                    </x-ui.list-row>
                @endforeach
            </x-ui.inset-grouped-list>
        @endif
    </div>
</div>
