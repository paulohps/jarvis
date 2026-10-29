{{--
    The invoice history: filters by status, payer and month, then the
    invoices with number, payer, dates, currency, amount, status and the PDF.
    A user with invoices in several workspaces sees each invoice's workspace
    under its number.
--}}
@php($showsWorkspaces = $this->showsWorkspaces())
<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('invoices.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Invoices') }}
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end" data-invoice-filters>
        <x-select.native :label="__('Status')" wire:model.live="status" data-invoice-filter="status">
            <option value="">{{ __('All') }}</option>
            @foreach ($this->statuses as $option)
                <option value="{{ $option->slug }}" wire:key="status-option-{{ $option->slug }}">{{ __(\Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $option->slug))) }}</option>
            @endforeach
        </x-select.native>

        <x-select.native :label="__('Payer')" wire:model.live="payer" data-invoice-filter="payer">
            <option value="">{{ __('All') }}</option>
            @foreach ($this->payers as $option)
                <option value="{{ $option->friendly_id }}" wire:key="payer-option-{{ $option->friendly_id }}">{{ $option->name }}</option>
            @endforeach
        </x-select.native>

        <x-input type="month" :label="__('Month')" wire:model.live="month" data-invoice-filter="month" />

        @if ($this->hasFilters())
            <x-button :text="__('Clear')" icon="x-mark" flat color="secondary" wire:click="clearFilters" />
        @endif
    </div>

    @if ($this->invoices->isEmpty())
        <x-ui.empty-state icon="document-text"
                          :title="$this->hasFilters() ? __('No invoices match the filters') : __('No invoices yet')"
                          :description="__('Invoices issued by your recurrences, or sent by hand, show up here.')" />
    @else
        <div class="overflow-x-auto rounded-card bg-secondary-grouped-background shadow-soft">
            <table class="w-full min-w-[48rem] text-left" data-invoice-history>
                <thead>
                    <tr class="border-b border-separator">
                        @foreach ([__('Number'), __('Payer'), __('Date'), __('Due date'), __('Currency'), __('Amount'), __('Status'), ''] as $heading)
                            <th scope="col" class="px-4 py-2 text-[0.8125rem] font-normal text-secondary-label uppercase">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-separator">
                    @foreach ($this->invoices as $invoice)
                        <tr wire:key="invoice-{{ $invoice->friendly_id }}" data-invoice-row="{{ $invoice->number }}" class="text-[0.9375rem] text-label">
                            <th scope="row" class="px-4 py-3 font-medium">
                                <a href="{{ route('invoices.history.show', $invoice) }}" wire:navigate class="text-tint hover:underline">{{ $invoice->number }}</a>

                                @if ($showsWorkspaces)
                                    <div class="font-normal">
                                        @include('livewire.invoices.partials.workspace-badge', ['workspace' => $invoice->workspace])
                                    </div>
                                @endif
                            </th>
                            <td class="px-4 py-3">{{ $invoice->payer_name }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $invoice->issue_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">{{ $invoice->currency->slug }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ number_format((float) $invoice->amount, 2, '.', ',') }}</td>
                            <td class="px-4 py-3"><x-ui.status-badge :status="$invoice->status->slug" /></td>
                            <td class="px-4 py-3 text-right">
                                @if ($invoice->pdf_path !== null)
                                    <x-button.circle icon="arrow-down-tray" flat color="secondary" sm
                                                     :title="__('Download PDF')"
                                                     :href="route('invoices.history.pdf', $invoice)" />
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->invoices->links() }}
    @endif
</div>
