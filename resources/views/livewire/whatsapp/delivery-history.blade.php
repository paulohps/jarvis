{{--
    The WhatsApp history: every delivery from the user's own accounts, with
    date, account, target, the beginning of the text and the status.
--}}
<div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('whatsapp.messages.index') }}" wire:navigate
           class="inline-flex items-center gap-1 text-[0.9375rem] text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
            <x-icon name="chevron-left" class="size-4" aria-hidden="true" />
            {{ __('Messages') }}
        </a>
    </div>

    @if ($this->deliveries->isEmpty())
        <x-ui.empty-state icon="clock"
                          :title="__('Nothing sent yet')"
                          :description="__('Messages sent from your numbers — scheduled, sent now or notifications — show up here.')" />
    @else
        <div class="overflow-x-auto rounded-card bg-secondary-grouped-background shadow-soft">
            <table class="w-full min-w-[48rem] text-left" data-whatsapp-history>
                <thead>
                    <tr class="border-b border-separator">
                        @foreach ([__('Date'), __('Account'), __('To'), __('Message'), __('Status')] as $heading)
                            <th scope="col" class="px-4 py-2 text-[0.8125rem] font-normal text-secondary-label uppercase">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-separator">
                    @foreach ($this->deliveries as $delivery)
                        <tr wire:key="delivery-{{ $delivery->id }}" data-delivery-row="{{ $delivery->id }}" class="text-[0.9375rem] text-label">
                            <td class="px-4 py-3 whitespace-nowrap"><x-ui.localized-date :value="$delivery->sent_at ?? $delivery->created_at" /></td>
                            <td class="px-4 py-3">{{ $delivery->account->nickname }}</td>
                            <td class="px-4 py-3">{{ $delivery->target->displayName() }}</td>
                            <td class="px-4 py-3 text-secondary-label">
                                {{ \Illuminate\Support\Str::limit(\Illuminate\Support\Str::squish($delivery->body), \App\Livewire\Whatsapp\DeliveryHistory::EXCERPT_LENGTH) }}
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.status-badge :status="$delivery->status->slug" />
                                @if (filled($delivery->error_message))
                                    <p class="mt-1 max-w-56 text-[0.75rem] text-danger">{{ $delivery->error_message }}</p>
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
