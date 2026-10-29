{{--
    The sendings of the messages scheduled on the chat screen: date, the
    conversation with its channel's mark, what went and the status, with
    why a failed one failed. The WhatsApp screen's history is a link away.
--}}
<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('chats.scheduled') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Scheduled') }}
        </a>

        @can('access-module', \App\Models\Module::WHATSAPP)
            <a href="{{ route('whatsapp.history.index') }}" wire:navigate data-chat-link="whatsapp-history"
               class="text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">{{ __('History of the WhatsApp screen') }}</a>
        @endcan
    </div>

    @if ($this->deliveries->isEmpty())
        <x-ui.empty-state icon="queue-list"
                          :title="__('Nothing sent yet')"
                          :description="__('The messages scheduled in the inbox show up here as they go out.')" />
    @else
        <div class="overflow-x-auto rounded-card bg-secondary-grouped-background shadow-soft">
            <table class="w-full min-w-[42rem] text-left" data-chat-history>
                <thead>
                    <tr class="border-b border-separator">
                        @foreach ([__('Date'), __('To'), __('Message'), __('Status')] as $heading)
                            <th scope="col" class="px-4 py-2 text-[0.8125rem] font-normal text-secondary-label uppercase">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-separator">
                    @foreach ($this->deliveries as $delivery)
                        <tr wire:key="delivery-{{ $delivery->friendly_id }}" data-chat-delivery="{{ $delivery->friendly_id }}" class="text-[0.9375rem] text-label">
                            <td class="px-4 py-3 whitespace-nowrap"><x-ui.localized-date :value="$delivery->created_at" /></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5">
                                    @if ($delivery->conversation !== null)
                                        <x-chats.provider-icon :provider="$delivery->conversation->provider->slug" :title="$delivery->conversation->provider->name" class="size-4 shrink-0" />
                                        <a href="{{ route('chats.chat', ['conversation' => $delivery->conversation->friendly_id]) }}" class="hover:underline">{{ $delivery->conversation->displayName() }}</a>
                                    @else
                                        {{ $delivery->conversation_name }}
                                    @endif
                                </span>
                            </td>
                            <td class="px-4 py-3 text-secondary-label">
                                @if ($delivery->scheduledMessage?->writesWithAi())
                                    <span aria-label="{{ __('Written by the AI') }}">✨</span>
                                @endif
                                {{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish((string) ($delivery->body ?? $delivery->scheduledMessage?->ai_prompt)), \App\Livewire\Chats\SendingHistory::EXCERPT_LENGTH) }}
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.status-badge :status="$delivery->status" />
                                @if (filled($delivery->error))
                                    <p class="mt-1 max-w-56 text-[0.75rem] text-danger" data-chat-delivery-error>{{ $delivery->error }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $this->deliveries->links() }}
    @endif
</div>
