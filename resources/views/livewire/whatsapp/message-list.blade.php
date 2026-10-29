{{--
    The user's WhatsApp messages with account, recipients, state and next
    run, each with "Send now", and the links to a new message, the history
    and the accounts.
--}}
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-ui.inset-grouped-list>
        <x-ui.list-row :title="__('History')" :subtitle="__('Every message sent from your numbers, with its status.')" icon="clock"
                       :href="route('whatsapp.history.index')" wire:navigate data-whatsapp-link="history" />
        <x-ui.list-row :title="__('WhatsApp accounts')" :subtitle="__('The numbers you send from.')" icon="device-phone-mobile"
                       :href="route('whatsapp.accounts.index')" wire:navigate data-whatsapp-link="accounts" />
    </x-ui.inset-grouped-list>

    @if (filled($status))
        <p role="status" data-message-status class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $status }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-message-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[1.25rem] font-semibold text-label">{{ __('Messages') }}</h2>

            <x-button :text="__('New message')" icon="plus" :href="route('whatsapp.messages.create')" wire:navigate data-message-create />
        </div>

        @if ($this->messages->isEmpty())
            <x-ui.empty-state icon="chat-bubble-left-right"
                              :title="__('No WhatsApp messages yet')"
                              :description="__('Schedule a message once or on a recurring schedule, to contacts and groups.')" />
        @else
            <x-ui.inset-grouped-list>
                @foreach ($this->messages as $whatsappMessage)
                    @php($state = $whatsappMessage->scheduleState())
                    <x-ui.list-row wire:key="whatsapp-message-{{ $whatsappMessage->friendly_id }}"
                                   data-whatsapp-message="{{ $whatsappMessage->friendly_id }}"
                                   :title="match (true) { $whatsappMessage->writesWithAi() => '✨ '.\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish((string) $whatsappMessage->ai_prompt), 68), $whatsappMessage->sendsPoll() => '📊 '.\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($whatsappMessage->body), 68), default => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($whatsappMessage->body), 70) }"
                                   :subtitle="$whatsappMessage->account->nickname.' · '.trans_choice('{1} 1 recipient|[2,*] :count recipients', $whatsappMessage->targets_count, ['count' => $whatsappMessage->targets_count])"
                                   :href="route('whatsapp.messages.edit', $whatsappMessage)">
                        <p class="text-[0.8125rem] text-secondary-label">
                            @if ($state === 'active')
                                {{ __('Next run') }} <x-ui.localized-date :value="$whatsappMessage->recurrence->next_run_at" data-message-next-run />
                            @else
                                {{ __('No next run') }}
                            @endif
                        </p>

                        <x-slot:trailing>
                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.status-badge :status="$state" />

                                <x-ui.confirm-dialog :title="__('Send this message now?')"
                                                     :description="__('It is sent right away to its recipients. Its schedule does not change.')"
                                                     :confirm-label="__('Send now')"
                                                     wire:click="sendNow('{{ $whatsappMessage->friendly_id }}')">
                                    <x-slot:trigger>
                                        <x-button.circle icon="paper-airplane" flat color="secondary" sm :title="__('Send now')"
                                                         data-message-quick-send="{{ $whatsappMessage->friendly_id }}" />
                                    </x-slot:trigger>
                                </x-ui.confirm-dialog>
                            </div>
                        </x-slot:trailing>
                    </x-ui.list-row>
                @endforeach
            </x-ui.inset-grouped-list>
        @endif
    </div>
</div>
