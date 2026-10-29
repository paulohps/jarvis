{{--
    The messages scheduled on the chat screen, of every channel: the
    conversation with its channel's mark, when next (and whether it
    repeats), the text or the AI's prompt, opening the conversation or
    cancelled. The WhatsApp screen's own scheduled messages are a link away.
--}}
@php($timezone = auth()->user()->timezone)
<div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <x-ui.inset-grouped-list>
        <x-ui.list-row :title="__('Sending history')" :subtitle="__('Every scheduled message sent or failed, with what went.')" icon="queue-list"
                       :href="route('chats.history')" wire:navigate data-chat-link="history" />
        @if ($this->hasWhatsappScreen())
            <x-ui.list-row :title="__('Scheduled on the WhatsApp screen')" :subtitle="__('Messages to many WhatsApp chats at once, with files and templates.')" icon="clock"
                           :href="route('whatsapp.messages.index')" wire:navigate data-chat-link="whatsapp-scheduled" />
        @endif
    </x-ui.inset-grouped-list>

    @if (filled($notice))
        <p role="status" data-scheduled-notice class="rounded-control bg-success/10 px-4 py-3 text-sm text-success">{{ $notice }}</p>
    @endif

    @if (filled($error))
        <p role="alert" data-scheduled-error class="rounded-control bg-danger/10 px-4 py-3 text-sm text-danger">{{ $error }}</p>
    @endif

    @if ($this->scheduled->isEmpty())
        <x-ui.empty-state icon="clock"
                          :title="__('Nothing scheduled')"
                          :description="__('In a conversation of the inbox, schedule a message once or on a recurring schedule, written by you or by the AI when it goes out.')" />
    @else
        <x-ui.inset-grouped-list>
            @foreach ($this->scheduled as $scheduledMessage)
                @php($next = $scheduledMessage->nextSendAt())
                <x-ui.list-row wire:key="scheduled-{{ $scheduledMessage->friendly_id }}"
                               data-chat-scheduled="{{ $scheduledMessage->friendly_id }}"
                               :title="$scheduledMessage->writesWithAi() ? '✨ '.\Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish((string) $scheduledMessage->ai_prompt), 68) : \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish((string) $scheduledMessage->body), 70)"
                               :href="$this->conversationUrl($scheduledMessage->conversation)">
                    <x-slot:leading>
                        <span class="grid size-8 place-items-center rounded-control bg-fill">
                            <x-chats.provider-icon :provider="$scheduledMessage->conversation->provider->slug" :title="$scheduledMessage->conversation->provider->name" class="size-4" />
                        </span>
                    </x-slot:leading>

                    <p class="truncate text-[0.8125rem] text-secondary-label">
                        {{ $scheduledMessage->conversation->displayName() }}
                        @if ($next !== null)
                            · {{ $next->setTimezone($timezone)->isoFormat('lll') }}
                        @endif
                        @if ($scheduledMessage->repeats())
                            · <span data-chat-scheduled-repeats>{{ __('Repeats') }}</span>
                        @endif
                    </p>

                    <x-slot:trailing>
                        <x-ui.confirm-dialog :title="__('Cancel this scheduled message?')"
                                             :description="$scheduledMessage->repeats() ? __('It stops repeating; what was sent stays in the history.') : __('It is not sent.')"
                                             :confirm-label="__('Cancel message')"
                                             wire:click="cancel('{{ $scheduledMessage->friendly_id }}')">
                            <x-slot:trigger>
                                <x-button.circle icon="x-mark" flat color="secondary" sm :title="__('Cancel')"
                                                 data-chat-scheduled-cancel="{{ $scheduledMessage->friendly_id }}" />
                            </x-slot:trigger>
                        </x-ui.confirm-dialog>
                    </x-slot:trailing>
                </x-ui.list-row>
            @endforeach
        </x-ui.inset-grouped-list>
    @endif
</div>
