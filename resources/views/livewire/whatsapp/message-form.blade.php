{{--
    A WhatsApp message: the account it goes from, the contacts, groups and
    typed numbers it goes to, the body with its preview and the schedule.
    "Send now" sends the form as it is; a saved message also shows its state
    and next run, with pause / resume, cancel and delete.
--}}
@php
    $existing = $this->message;
    $schedule = $existing?->recurrence;
    $state = $existing?->scheduleState();
@endphp

<div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('whatsapp.messages.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Messages') }}
        </a>

        <div class="flex flex-wrap items-center gap-2">
            @unless ($withAi && ! $withPoll)
            <x-ui.confirm-dialog :title="__('Send this message now?')"
                                 :description="__('It is sent right away to every recipient chosen below. Nothing is scheduled and the next run does not change.')"
                                 :confirm-label="__('Send now')"
                                 wire:click="sendNow">
                <x-slot:trigger>
                    <x-button :text="__('Send now')" icon="paper-airplane" flat color="secondary" data-message-send-now />
                </x-slot:trigger>
            </x-ui.confirm-dialog>
            @endunless

            @if ($existing !== null && $state !== 'cancelled')
                @if ($existing->isOnce())
                    @if ($state === 'active')
                        <x-ui.confirm-dialog :title="__('Cancel this message?')"
                                             :description="__('It will not be sent. A cancelled message cannot be scheduled again.')"
                                             :confirm-label="__('Cancel message')"
                                             danger
                                             wire:click="cancel">
                            <x-slot:trigger>
                                <x-button :text="__('Cancel message')" icon="x-circle" flat color="secondary" data-message-cancel />
                            </x-slot:trigger>
                        </x-ui.confirm-dialog>
                    @endif
                @elseif ($state === 'paused')
                    <x-button :text="__('Resume')" icon="play" flat color="secondary" wire:click="resume" loading="resume" data-message-resume />
                @else
                    <x-button :text="__('Pause')" icon="pause" flat color="secondary" wire:click="pause" loading="pause" data-message-pause />
                @endif
            @endif

            @if ($existing !== null)
                <x-ui.confirm-dialog :title="__('Delete this message?')"
                                     :description="__('Its schedule stops. What was already sent stays in the history.')"
                                     :confirm-label="__('Delete')"
                                     danger
                                     wire:click="delete">
                    <x-slot:trigger>
                        <x-button :text="__('Delete')" icon="trash" flat color="red" />
                    </x-slot:trigger>
                </x-ui.confirm-dialog>
            @endif
        </div>
    </div>

    @if (filled($status))
        <p role="status" data-message-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @error('message')
        <p role="alert" data-message-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($existing !== null)
        <x-ui.inset-grouped-list>
            <x-ui.list-row :title="__('Status')" icon="arrow-path">
                <x-slot:trailing>
                    <x-ui.status-badge :status="$state" data-message-state="{{ $state }}" />
                </x-slot:trailing>
            </x-ui.list-row>

            <x-ui.list-row :title="__('Next run')" icon="calendar">
                <x-slot:trailing>
                    <span data-message-next-run>
                        @if ($state !== 'active')
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

    <form wire:submit="save" class="flex flex-col gap-6" novalidate data-whatsapp-message-form>
        <x-ui.settings-section :title="__('From')" :description="__('One of your connected numbers.')">
            @if ($this->accounts->isEmpty())
                <x-ui.empty-state icon="device-phone-mobile"
                                  :title="__('No connected number')"
                                  :description="__('Connect a WhatsApp number to send messages from it.')">
                    <x-slot:actions>
                        <x-button :text="__('Connect a number')" icon="plus" :href="route('whatsapp.accounts.connect')" wire:navigate />
                    </x-slot:actions>
                </x-ui.empty-state>
            @else
                <x-select.native :label="__('Account')" wire:model.live="account" data-message-account>
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach ($this->accounts as $option)
                        <option value="{{ $option->friendly_id }}" wire:key="account-option-{{ $option->friendly_id }}">
                            {{ $option->nickname }}{{ $option->phone_number ? ' · '.$option->phone_number : '' }}{{ $option->isOpen() ? '' : ' ('.__('not connected').')' }}
                        </option>
                    @endforeach
                </x-select.native>
            @endif
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('To')" :description="__('Contacts and groups of the account, or numbers typed in international format.')">
            <div class="flex flex-col gap-4" data-message-targets>
                @if ($this->chosenTargets->isNotEmpty() || $numbers !== [])
                    <ul class="flex flex-wrap gap-2" aria-label="{{ __('Recipients') }}">
                        @foreach ($this->chosenTargets as $target)
                            <li wire:key="chosen-{{ $target->friendly_id }}" data-chosen-target="{{ $target->friendly_id }}"
                                class="inline-flex items-center gap-1 rounded-full bg-tint-fill/10 py-1 pr-1 pl-3 text-[0.875rem] text-tint">
                                <x-icon :name="$target->type->slug === 'group' ? 'user-group' : 'user'" class="size-4" aria-hidden="true" />
                                {{ $target->displayName() }}
                                <button type="button" wire:click="removeTarget('{{ $target->friendly_id }}')"
                                        class="rounded-full p-0.5 hover:bg-tint-fill/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                        aria-label="{{ __('Remove :name', ['name' => $target->displayName()]) }}">
                                    <x-icon name="x-mark" class="size-3.5" aria-hidden="true" />
                                </button>
                            </li>
                        @endforeach

                        @foreach ($numbers as $index => $number)
                            <li wire:key="number-{{ $index }}" data-chosen-number="{{ $number }}"
                                class="inline-flex items-center gap-1 rounded-full bg-fill py-1 pr-1 pl-3 text-[0.875rem] text-label">
                                <x-icon name="phone" class="size-4" aria-hidden="true" />
                                {{ $number }}
                                <button type="button" wire:click="removeNumber({{ $index }})"
                                        class="rounded-full p-0.5 hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                        aria-label="{{ __('Remove :name', ['name' => $number]) }}">
                                    <x-icon name="x-mark" class="size-3.5" aria-hidden="true" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @error('targets')
                    <p class="text-sm text-danger">{{ $message }}</p>
                @enderror
                @foreach ($errors->get('numbers.*') as $messages)
                    @foreach ($messages as $error)
                        <p class="text-sm text-danger">{{ $error }}</p>
                    @endforeach
                @endforeach

                @if ($account !== '')
                    <x-ui.search-field wire:model.live.debounce.300ms="targetSearch" :placeholder="__('Search contacts and groups')" data-target-search />

                    @if ($this->targetOptions->isEmpty())
                        <p class="text-sm text-secondary-label">{{ __('No contacts or groups found.') }}</p>
                    @else
                        <ul class="flex max-h-64 flex-col divide-y divide-separator overflow-y-auto rounded-control bg-fill/50" data-target-options>
                            @foreach ($this->targetOptions as $option)
                                <li wire:key="target-option-{{ $option->friendly_id }}">
                                    <button type="button" wire:click="addTarget('{{ $option->friendly_id }}')"
                                            data-target-option="{{ $option->friendly_id }}"
                                            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[0.9375rem] text-label hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                                        <x-icon :name="$option->type->slug === 'group' ? 'user-group' : 'user'" class="size-4 text-secondary-label" aria-hidden="true" />
                                        <span class="flex-1">{{ $option->displayName() }}</span>
                                        @if ($option->phoneNumber() !== null && filled($option->name))
                                            <span class="text-[0.8125rem] text-secondary-label">{{ $option->phoneNumber() }}</span>
                                        @endif
                                        <x-icon name="plus" class="size-4 text-tint" aria-hidden="true" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif

                <div class="flex items-start gap-2">
                    <div class="flex-1">
                        <x-input wire:model="newNumber" wire:keydown.enter.prevent="addNumber" :label="__('Type a number')" placeholder="+5511999990000" inputmode="tel" autocomplete="off" data-new-number />
                    </div>
                    <div class="pt-6">
                        <x-button :text="__('Add')" icon="plus" flat wire:click="addNumber" data-add-number />
                    </div>
                </div>
            </div>
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('Message')" :description="$withPoll ? __('The same poll goes to every recipient.') : __('Sent as plain text. The variables are filled in for each recipient.')">
            <div class="flex flex-col gap-4">
                <x-toggle wire:model.live="withPoll" :label="__('Send a poll')" data-message-with-poll />

                @if (! $withPoll)
                    <x-toggle wire:model.live="withAi" :label="__('Written by the AI when it goes out')" data-message-with-ai />
                @endif

                @if ($withPoll)
                    <x-input :label="__('Question')" wire:model="pollQuestion" maxlength="255" data-message-poll-question />

                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-2 text-sm font-medium text-label">{{ __('Options') }}</legend>
                        @foreach ($pollOptions as $index => $option)
                            <div class="flex items-center gap-2" wire:key="message-poll-option-{{ $index }}">
                                <div class="flex-1">
                                    <x-input wire:model="pollOptions.{{ $index }}" maxlength="100" :placeholder="__('Option :number', ['number' => $index + 1])" :aria-label="__('Option :number', ['number' => $index + 1])" data-message-poll-option="{{ $index }}" />
                                </div>
                                @if (count($pollOptions) > \App\Actions\Whatsapp\SendWhatsappChatContent::MIN_POLL_OPTIONS)
                                    <x-button.circle icon="x-mark" flat color="secondary" sm :title="__('Remove')" wire:click="removePollOption({{ $index }})" />
                                @endif
                            </div>
                        @endforeach
                        @error('pollOptions')
                            <p role="alert" class="text-sm text-danger">{{ $message }}</p>
                        @enderror
                        @foreach ($errors->get('pollOptions.*') as $optionErrors)
                            <p role="alert" class="text-sm text-danger">{{ $optionErrors[0] }}</p>
                        @endforeach
                        @if (count($pollOptions) < \App\Actions\Whatsapp\SendWhatsappChatContent::MAX_POLL_OPTIONS)
                            <div>
                                <x-button type="button" :text="__('Add an option')" icon="plus" flat sm color="secondary" wire:click="addPollOption" data-message-poll-add />
                            </div>
                        @endif
                    </fieldset>

                    <x-toggle wire:model="pollMultiple" :label="__('Allow more than one answer')" data-message-poll-multiple />
                @elseif ($withAi)
                    <x-textarea wire:model="aiPrompt" :label="__('What the AI should write')" rows="4" maxlength="{{ \App\Actions\Whatsapp\ScheduleWhatsappMessage::MAX_AI_PROMPT }}"
                                :placeholder="__('E.g. wish a good morning with a different motivational quote each day')"
                                :hint="__('At each sending the AI writes a new message to each recipient in your style, reading the latest messages of their chat.')"
                                data-message-ai-prompt />
                @else
                    <x-ui.template-editor :schema="$this->schema" :label="__('Body')" wire:model.live.debounce.500ms="body" :rows="6"
                                          :placeholder="__('Hi {% recipient.name %}, …')" />

                    <x-whatsapp.media-picker property="file" :file="$file"
                                             :kept="$removesMedia ? null : ($existing?->media_path !== null ? ($existing->media_name ?? __('Voice note')) : null)" />

                    @if ($this->bodyPreview !== null)
                        <x-ui.template-preview :preview="$this->bodyPreview" data-message-preview />
                    @endif
                @endif
            </div>
        </x-ui.settings-section>

        <x-ui.settings-section :title="__('Schedule')">
            <x-ui.recurrence-picker :form="$recurrence" />
        </x-ui.settings-section>

        <div class="flex justify-end gap-2">
            <x-button type="submit" :text="$existing === null ? __('Schedule message') : __('Save')" icon="check" loading="save" data-message-save />
        </div>
    </form>
</div>
