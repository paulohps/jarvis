{{--
    The bell of the top bar: the unread count, and on opening the latest
    notifications. The list is read only once the panel opens (`loadItems`).
--}}
<div class="flex">
    <x-dropdown position="bottom-end" width="2xl">
        <x-slot:action>
            <button type="button"
                    x-on:click="show = !show; if (show) { $wire.loadItems() }"
                    x-bind:aria-expanded="show"
                    aria-haspopup="menu"
                    data-notification-bell
                    class="relative grid size-10 place-items-center rounded-full text-secondary-label transition hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                <x-icon name="bell" class="size-6" aria-hidden="true" />
                <span class="sr-only">{{ __('Notifications') }}</span>

                @if ($this->badge !== null)
                    <span data-unread-count
                          class="absolute top-0.5 right-0.5 grid h-4.5 min-w-4.5 place-items-center rounded-full bg-danger px-1 text-[10px] leading-none font-bold text-on-tint tabular-nums">
                        <span aria-hidden="true">{{ $this->badge }}</span>
                        <span class="sr-only">{{ __('Unread: :count', ['count' => $this->badge]) }}</span>
                    </span>
                @endif
            </button>
        </x-slot:action>

        <x-slot:header>
            <div class="flex items-center justify-between gap-3 px-2 py-1">
                <p class="text-[0.9375rem] font-semibold text-label">{{ __('Notifications') }}</p>

                @if ($unreadCount > 0)
                    <button type="button"
                            wire:click="markAllAsRead"
                            data-mark-all-read
                            class="rounded-control text-[0.8125rem] font-medium text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                        {{ __('Mark all as read') }}
                    </button>
                @endif
            </div>
        </x-slot:header>

        <div class="w-80 whitespace-normal sm:w-96">
            <div class="max-h-96 divide-y divide-separator overflow-y-auto">
                @if (! $isLoaded)
                    <p class="px-4 py-6 text-center text-[0.875rem] text-secondary-label">{{ __('Loading…') }}</p>
                @elseif ($this->items->isEmpty())
                    <p class="px-4 py-6 text-center text-[0.875rem] text-secondary-label">{{ __('You have no notifications yet.') }}</p>
                @else
                    @foreach ($this->items as $item)
                        <a href="{{ route('notifications.open', $item->id) }}"
                           wire:key="bell-notification-{{ $item->id }}"
                           data-notification="{{ $item->id }}"
                           @class([
                               'flex w-full items-start gap-2.5 px-3 py-3 text-left transition-colors hover:bg-fill focus:bg-fill focus:outline-none',
                               'bg-tint-fill/5' => ! $item->isRead,
                           ])>
                            <span aria-hidden="true"
                                  @class([
                                      'mt-1.5 size-2 shrink-0 rounded-full',
                                      'bg-tint' => ! $item->isRead,
                                      'bg-transparent' => $item->isRead,
                                  ])></span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-[0.875rem] font-semibold text-label">
                                    @unless ($item->isRead)
                                        <span class="sr-only">{{ __('Unread notification') }}:</span>
                                    @endunless
                                    {{ $item->title }}
                                </span>
                                <span class="mt-0.5 line-clamp-2 block text-[0.8125rem] text-secondary-label">{{ strip_tags($item->body) }}</span>
                                <span class="mt-1 block text-[0.75rem] text-secondary-label">
                                    <x-ui.localized-date :value="$item->createdAt" />
                                </span>
                            </span>
                        </a>
                    @endforeach
                @endif
            </div>

            <a href="{{ route('notifications.index') }}"
               wire:navigate
               class="block border-t border-separator px-4 py-3 text-center text-[0.875rem] font-medium text-tint transition-colors hover:bg-fill focus:outline-none focus-visible:bg-fill">
                {{ __('View all notifications') }}
            </a>
        </div>
    </x-dropdown>
</div>
