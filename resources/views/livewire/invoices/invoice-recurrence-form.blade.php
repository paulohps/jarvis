{{--
    An invoice recurrence: who is billed and by whom, the recipients, the
    e-mail templates, what is billed, the date rules, the sending mode and the
    schedule. An existing one shows its status and next run, with pause /
    resume and delete. A user with invoices in several workspaces picks the
    workspace of a new recurrence (the payers and issuers listed are those of
    it); an existing one shows its workspace, which does not change.
--}}
@php
    $existing = $this->invoiceRecurrence;
    $schedule = $existing?->recurrence;
    $isPaused = (bool) $schedule?->is_paused;
@endphp

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('invoices.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Invoices') }}
        </a>

        @if ($existing !== null)
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.confirm-dialog :title="__('Send an invoice now?')"
                                     :description="__('An invoice is issued for today with the saved settings and e-mailed to the recipients. The next scheduled run does not change.')"
                                     :confirm-label="__('Send now')"
                                     wire:click="sendNow">
                    <x-slot:trigger>
                        <x-button :text="__('Send now')" icon="paper-airplane" flat color="secondary" data-recurrence-send-now />
                    </x-slot:trigger>
                </x-ui.confirm-dialog>

                @if ($isPaused)
                    <x-button :text="__('Resume')" icon="play" flat color="secondary" wire:click="resume" loading="resume" data-recurrence-resume />
                @else
                    <x-button :text="__('Pause')" icon="pause" flat color="secondary" wire:click="pause" loading="pause" data-recurrence-pause />
                @endif

                <x-ui.confirm-dialog :title="__('Delete :name?', ['name' => $existing->name])"
                                     :description="__('Its schedule stops. Invoices already issued are kept.')"
                                     :confirm-label="__('Delete')"
                                     danger
                                     wire:click="delete">
                    <x-slot:trigger>
                        <x-button :text="__('Delete')" icon="trash" flat color="red" />
                    </x-slot:trigger>
                </x-ui.confirm-dialog>
            </div>
        @endif
    </div>

    @if (filled($status))
        <p role="status" data-recurrence-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if ($existing !== null)
        <x-ui.inset-grouped-list>
            <x-ui.list-row :title="__('Status')" icon="arrow-path">
                <x-slot:trailing>
                    <x-ui.status-badge :status="$isPaused ? 'paused' : 'active'" data-recurrence-state="{{ $isPaused ? 'paused' : 'active' }}" />
                </x-slot:trailing>
            </x-ui.list-row>

            <x-ui.list-row :title="__('Next run')" icon="calendar">
                <x-slot:trailing>
                    <span data-recurrence-next-run>
                        @if ($isPaused || $schedule?->next_run_at === null)
                            {{ __('No next run') }}
                        @else
                            <x-ui.localized-date :value="$schedule->next_run_at" />
                            <span class="text-xs">({{ auth()->user()->timezone }})</span>
                        @endif
                    </span>
                </x-slot:trailing>
            </x-ui.list-row>
        </x-ui.inset-grouped-list>
    @endif

    <form wire:submit="save" class="flex flex-col gap-6" novalidate data-invoice-recurrence-form>
        <x-ui.settings-section :title="__('Billing')" :description="__('Who is billed, who issues the invoice and who receives it.')">
            <div class="flex flex-col gap-5">
                <x-input :label="__('Name')" wire:model="name" maxlength="100" :placeholder="__('e.g. Monthly invoice')" required />

                @if ($this->showsWorkspaces())
                    @if ($existing === null)
                        <x-select.native :label="__('Workspace')" wire:model.live="workspace" data-recurrence-workspace>
                            @foreach ($this->workspaceOptions as $workspaceId => $workspaceName)
                                <option value="{{ $workspaceId }}" wire:key="workspace-option-{{ $workspaceId }}">{{ $workspaceName }}</option>
                            @endforeach
                        </x-select.native>
                    @else
                        <div class="flex flex-col gap-1">
                            <p class="text-sm font-medium text-label">{{ __('Workspace') }}</p>
                            @include('livewire.invoices.partials.workspace-badge', ['workspace' => $existing->workspace])
                        </div>
                    @endif
                @endif

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-select.native :label="__('Payer')" wire:model="payer" data-recurrence-payer>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->payers as $option)
                            <option value="{{ $option->friendly_id }}" wire:key="payer-option-{{ $option->friendly_id }}">{{ $option->name }}</option>
                        @endforeach
                    </x-select.native>

                    <x-select.native :label="__('Issuer')" wire:model="issuer" data-recurrence-issuer>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($this->issuers as $option)
                            <option value="{{ $option->friendly_id }}" wire:key="issuer-option-{{ $option->friendly_id }}">{{ $option->name }}</option>
                        @endforeach
                    </x-select.native>
                </div>

                <div class="flex flex-col gap-2" data-recurrence-recipients>
                    <p class="text-sm font-medium text-label">{{ __('Recipients') }}</p>

                    @foreach ($recipients as $index => $recipient)
                        <div class="flex items-start gap-2" wire:key="recipient-{{ $index }}">
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
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('Invoice')" :description="__('What each invoice bills. The dates are computed at every run.')">
            <div class="flex flex-col gap-5">
                <x-textarea :label="__('Description')" wire:model="service_description" rows="5" required />

                <div class="grid gap-5 sm:grid-cols-[10rem_1fr]">
                    <x-select.native :label="__('Currency')" wire:model="currency">
                        @foreach ($this->currencies as $option)
                            <option value="{{ $option->slug }}" wire:key="currency-{{ $option->slug }}">{{ $option->slug }} · {{ $option->label() }}</option>
                        @endforeach
                    </x-select.native>

                    <x-input :label="__('Amount')" wire:model="amount" inputmode="decimal" placeholder="2000.00" required />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="flex flex-col gap-3">
                        <x-select.native :label="__('Invoice date')" wire:model.live="invoice_date_rule">
                            @foreach ($this->dateRuleOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select.native>

                        @if ($invoice_date_rule === \App\Models\InvoiceDateRule::DAY_OF_CURRENT_MONTH)
                            <x-input type="number" min="1" max="31" :label="__('Day of the month')"
                                     :hint="__('Shorter months use their last day.')" wire:model="invoice_date_day" />
                        @endif
                    </div>

                    <div class="flex flex-col gap-3">
                        <x-select.native :label="__('Due date')" wire:model.live="invoice_due_date_rule">
                            @foreach ($this->dueDateRuleOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select.native>

                        @if ($invoice_due_date_rule === \App\Models\InvoiceDueDateRule::DAYS_AFTER_ISSUE)
                            <x-input type="number" min="0" max="365" :label="__('Days after the invoice date')" wire:model="due_date_value" />
                        @elseif ($invoice_due_date_rule === \App\Models\InvoiceDueDateRule::DAY_OF_NEXT_MONTH)
                            <x-input type="number" min="1" max="31" :label="__('Day of the next month')"
                                     :hint="__('Shorter months use their last day.')" wire:model="due_date_value" />
                        @endif
                    </div>
                </div>
            </div>
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('E-mail')" :description="__('Sent with the PDF attached. Use the variables to fill in the invoice details.')">
            <div class="flex flex-col gap-5">
                <x-select.native :label="__('E-mail language')" wire:model="locale" data-invoice-locale
                                 :hint="__('Month names, dates and the currency in the e-mail are written in this language, whatever yours is.')">
                    @foreach ($this->localeOptions() as $localeSlug => $localeName)
                        <option value="{{ $localeSlug }}" wire:key="invoice-locale-{{ $localeSlug }}">{{ $localeName }}</option>
                    @endforeach
                </x-select.native>

                <x-ui.template-editor :schema="$this->schema" :label="__('Subject')" wire:model="email_subject" :rows="2" mode="inline" />
                <x-ui.template-editor :schema="$this->schema" :label="__('Body')" wire:model="email_body" :rows="8" mode="rich" />

                <x-ui.segmented-control name="delivery_mode"
                                        wire:model="delivery_mode"
                                        :label="__('Sending')"
                                        :value="$delivery_mode"
                                        :options="$this->deliveryModeOptions()" />

                <x-select.native :label="__('Send through')" wire:model="mail_service" data-invoice-mail-service
                                 :hint="__('Your own mail services and the global ones. Add one in Profile → Preferences → Mail services.')">
                    <option value="">{{ __('Default mailer') }}</option>
                    @foreach ($this->mailServices as $mailService)
                        <option value="{{ $mailService->friendly_id }}" wire:key="invoice-mail-service-{{ $mailService->friendly_id }}">{{ $mailService->name }} ({{ $mailService->from_address }})</option>
                    @endforeach
                </x-select.native>
            </div>
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('Schedule')">
            <x-ui.recurrence-picker :form="$recurrence" />
        </x-ui.settings-section>

        <div class="flex justify-end gap-2">
            <x-button type="button" :text="__('Preview next invoice')" icon="eye" flat color="secondary"
                      wire:click="preview" loading="preview" data-invoice-recurrence-preview />
            <x-button type="submit" :text="__('Save')" icon="check" loading="save" data-invoice-recurrence-save />
        </div>
    </form>

    @if ($showPreview)
        @php($invoicePreview = $this->invoicePreview)

        <x-ui.settings-section :title="__('Next invoice preview')"
                               :description="__('What the next run would issue and send, as the form is now. Nothing is saved or sent, and no invoice number is used.')"
                               data-invoice-preview-panel>
            <x-slot:status>
                <x-button :text="__('Close')" icon="x-mark" sm flat color="secondary" wire:click="closePreview" />
            </x-slot:status>

            @if ($invoicePreview === null)
                <p role="alert" class="rounded-control bg-warning/10 px-4 py-3 text-sm text-warning" data-invoice-preview-incomplete>
                    {{ __('Choose a payer, an issuer, a currency and an amount above zero, with valid dates and schedule, to preview the next invoice.') }}
                </p>
            @else
                <div class="flex flex-col gap-5">
                    <dl class="grid gap-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-secondary-label">{{ __('Next run') }}</dt>
                            <dd class="font-medium text-label" data-invoice-preview-run>
                                @if ($invoicePreview->runAt === null)
                                    {{ __('No next run') }}
                                @else
                                    <x-ui.localized-date :value="$invoicePreview->runAt" />
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-secondary-label">{{ __('Invoice number') }}</dt>
                            <dd class="font-mono font-medium text-label" data-invoice-preview-number>{{ $invoicePreview->number() }}</dd>
                        </div>
                        <div>
                            <dt class="text-secondary-label">{{ __('Invoice date') }}</dt>
                            <dd class="font-medium text-label" data-invoice-preview-issue-date>{{ $invoicePreview->dates->issueDate->format(\App\Services\Invoices\InvoiceTemplateSchema::DATE_FORMAT) }}</dd>
                        </div>
                        <div>
                            <dt class="text-secondary-label">{{ __('Due date') }}</dt>
                            <dd class="font-medium text-label" data-invoice-preview-due-date>{{ $invoicePreview->dates->dueDate->format(\App\Services\Invoices\InvoiceTemplateSchema::DATE_FORMAT) }}</dd>
                        </div>
                    </dl>

                    <div class="flex flex-col gap-3" data-invoice-preview-email>
                        <p class="text-sm text-secondary-label">
                            {{ __('To') }}: <span class="text-label">{{ implode(', ', $invoicePreview->recipients) ?: '—' }}</span>
                        </p>

                        <x-ui.template-preview :preview="$invoicePreview->subject" :label="__('Subject')" />
                        <x-ui.template-preview :preview="$invoicePreview->body" :label="__('Body')" />
                    </div>

                    <div class="flex flex-col gap-2" data-invoice-preview-pdf>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[0.8125rem] font-medium text-secondary-label">{{ __('PDF') }}</p>

                            @if ($existing !== null)
                                <x-button :text="__('Open the PDF of the saved version')" icon="arrow-top-right-on-square" sm flat
                                          :href="route('invoices.recurrences.preview-pdf', $existing)" target="_blank" />
                            @endif
                        </div>

                        <iframe title="{{ __('PDF preview') }}"
                                sandbox=""
                                srcdoc="{{ $invoicePreview->pdfHtml() }}"
                                class="h-[60rem] w-full rounded-control border border-separator"></iframe>
                    </div>
                </div>
            @endif
        </x-ui.settings-section>
    @endif
</div>
