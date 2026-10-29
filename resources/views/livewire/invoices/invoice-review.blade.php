{{--
    One invoice: its details and delivery attempts. While it waits to be
    sent, the form edits it and the actions approve and send it or discard it.
--}}
@php
    $isEditable = $invoice->isEditable();
    $isCancellable = ($invoice->isAwaitingSend() || $invoice->isFailed()) && ! $invoice->hasPendingDelivery();
@endphp

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6" data-invoice="{{ $invoice->friendly_id }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('invoices.history.index') }}" wire:navigate data-invoice-back
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Invoice history') }}
        </a>

        <div class="flex flex-wrap items-center gap-2">
            @if ($invoice->pdf_path !== null)
                <x-button :text="__('Download PDF')" icon="arrow-down-tray" flat color="secondary" :href="route('invoices.history.pdf', $invoice)" data-invoice-pdf />
            @endif

            @if ($isCancellable)
                <x-ui.confirm-dialog :title="__('Cancel invoice :number?', ['number' => $invoice->number])"
                                     :description="__('It will not be sent. Its number stays reserved and is not reused.')"
                                     :confirm-label="__('Cancel invoice')"
                                     danger
                                     wire:click="cancel">
                    <x-slot:trigger>
                        <x-button :text="__('Cancel invoice')" icon="x-circle" flat color="red" data-invoice-cancel />
                    </x-slot:trigger>
                </x-ui.confirm-dialog>
            @endif

            @if ($invoice->isResendable() && ! $invoice->hasPendingDelivery())
                <x-button :text="__('Send again')" icon="arrow-path" flat color="secondary" wire:click="resend" loading="resend" data-invoice-resend />
            @endif

            @if ($isEditable)
                <x-button :text="__('Approve and send')" icon="paper-airplane" wire:click="approve" loading="approve" data-invoice-approve />
            @endif
        </div>
    </div>

    @if (filled($status))
        <p role="status" data-invoice-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @error('invoice')
        <p role="alert" data-invoice-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    <x-ui.inset-grouped-list :header="__('Invoice :number', ['number' => $invoice->number])">
        <x-ui.list-row :title="__('Status')" icon="flag">
            <x-slot:trailing>
                <x-ui.status-badge :status="$invoice->status->slug" data-invoice-state="{{ $invoice->status->slug }}" />
            </x-slot:trailing>
        </x-ui.list-row>

        @if ($this->showsWorkspaces())
            <x-ui.list-row :title="__('Workspace')" icon="user-group">
                <x-slot:trailing>
                    @include('livewire.invoices.partials.workspace-badge', ['workspace' => $invoice->workspace])
                </x-slot:trailing>
            </x-ui.list-row>
        @endif

        <x-ui.list-row :title="__('Bill to')" :subtitle="$invoice->payer_name" icon="building-office" />
        <x-ui.list-row :title="__('Issuer')" :subtitle="$invoice->issuer_name" icon="identification" />

        @if ($invoice->recurrence !== null)
            <x-ui.list-row :title="__('Recurrence')" :subtitle="$invoice->recurrence->name" icon="arrow-path"
                           :href="$invoice->recurrence->trashed() ? null : route('invoices.recurrences.edit', $invoice->recurrence)" />
        @endif

        <x-ui.list-row :title="__('Invoice date')" icon="calendar">
            <x-slot:trailing><span class="text-secondary-label">{{ $invoice->issue_date->format('d/m/Y') }}</span></x-slot:trailing>
        </x-ui.list-row>

        <x-ui.list-row :title="__('Due date')" icon="calendar-days">
            <x-slot:trailing><span class="text-secondary-label">{{ $invoice->due_date->format('d/m/Y') }}</span></x-slot:trailing>
        </x-ui.list-row>

        <x-ui.list-row :title="__('Amount')" icon="banknotes">
            <x-slot:trailing><span class="text-secondary-label"><x-ui.money :amount="$invoice->amount" :currency="$invoice->currency" /></span></x-slot:trailing>
        </x-ui.list-row>

        @if ($invoice->sent_at !== null)
            <x-ui.list-row :title="__('Sent')" icon="paper-airplane">
                <x-slot:trailing><span class="text-secondary-label"><x-ui.localized-date :value="$invoice->sent_at" /></span></x-slot:trailing>
            </x-ui.list-row>
        @endif
    </x-ui.inset-grouped-list>

    @if ($isEditable)
        <form wire:submit="save" class="flex flex-col gap-6" novalidate data-invoice-form>
            <x-ui.settings-section :title="__('Review')" :description="__('Change what this invoice bills before sending it. The PDF is updated when you save.')">
                <div class="flex flex-col gap-5">
                    <x-textarea :label="__('Description')" wire:model="service_description" rows="5" required />

                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-input type="date" :label="__('Invoice date')" wire:model="issue_date" required />
                        <x-input type="date" :label="__('Due date')" wire:model="due_date" required />
                        <x-input :label="__('Amount')" wire:model="amount" inputmode="decimal" placeholder="2000.00" required />
                    </div>

                    <div class="flex flex-col gap-2" data-invoice-recipients>
                        <p class="text-sm font-medium text-label">{{ __('Recipients') }}</p>

                        @foreach ($recipients as $index => $recipient)
                            <div class="flex items-start gap-2" wire:key="invoice-recipient-{{ $index }}">
                                <div class="flex-1">
                                    <x-input type="email" wire:model="recipients.{{ $index }}" :placeholder="__('billing@example.com')" autocomplete="off" />
                                </div>

                                <x-button.circle icon="minus" flat color="secondary" sm :title="__('Remove')" wire:click="removeRecipient({{ $index }})" />
                            </div>
                        @endforeach

                        @error('recipients')
                            <p class="text-sm text-danger">{{ $message }}</p>
                        @enderror

                        @if (count($recipients) < \App\Actions\Invoices\SaveInvoiceRecurrence::MAX_RECIPIENTS)
                            <div>
                                <x-button :text="__('Add recipient')" icon="plus" sm flat wire:click="addRecipient" />
                            </div>
                        @endif
                    </div>
                </div>

                <x-slot:actions>
                    <x-button type="submit" :text="__('Save')" icon="check" loading="save" />
                </x-slot:actions>
            </x-ui.settings-section>
        </form>
    @else
        <x-ui.settings-section :title="__('Description')">
            <p class="text-[0.9375rem] whitespace-pre-line text-label" data-invoice-description>{{ $invoice->service_description }}</p>
            <p class="mt-3 text-[0.8125rem] text-secondary-label">
                {{ __('To') }}: <span class="text-label">{{ $invoice->recipients->pluck('email')->implode(', ') ?: '—' }}</span>
            </p>
        </x-ui.settings-section>
    @endif

    <x-ui.inset-grouped-list :header="__('Deliveries')">
        @forelse ($this->deliveries as $delivery)
            <x-ui.list-row wire:key="delivery-{{ $delivery->id }}" data-invoice-delivery
                           :title="$delivery->triggeredBy?->name ?? __('Scheduler')"
                           icon="envelope">
                <p class="text-[0.8125rem] text-secondary-label">
                    <x-ui.localized-date :value="$delivery->sent_at ?? $delivery->created_at" />
                    @if (filled($delivery->error_message))
                        · <span class="text-danger">{{ $delivery->error_message }}</span>
                    @endif
                </p>

                <x-slot:trailing>
                    <x-ui.status-badge :status="$delivery->status->slug" />
                </x-slot:trailing>
            </x-ui.list-row>
        @empty
            <x-ui.list-row :title="__('Not sent yet')" icon="envelope" />
        @endforelse
    </x-ui.inset-grouped-list>
</div>
