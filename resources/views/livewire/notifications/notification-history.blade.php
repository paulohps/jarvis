<div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-ui.segmented-control name="filter"
                                wire:model.live="filter"
                                :label="__('Show')"
                                :value="$filter"
                                :options="['all' => __('All'), 'unread' => __('Unread')]" />

        @if ($this->unreadCount > 0)
            <x-button :text="__('Mark all as read')" icon="check" color="secondary" flat sm
                      wire:click="markAllAsRead" loading="markAllAsRead" data-mark-all-read />
        @endif
    </div>

    @if ($this->notifications->isEmpty())
        <x-ui.empty-state icon="bell"
                          :title="$filter === 'unread' ? __('No unread notifications') : __('You have no notifications yet.')"
                          :description="__('Notifications about your account and your modules show up here.')" />
    @else
        <ul role="list" class="flex flex-col gap-3">
            @foreach ($this->notifications as $item)
                <li wire:key="notification-{{ $item->id }}"
                    data-notification="{{ $item->id }}"
                    @class([
                        'flex flex-col gap-2 rounded-card bg-secondary-grouped-background px-4 py-3 shadow-soft',
                        'ring-1 ring-tint/40' => ! $item->isRead,
                    ])>
                    <div class="flex items-start justify-between gap-3">
                        <h2 class="text-[0.9375rem] font-semibold text-label">
                            @unless ($item->isRead)
                                <span aria-hidden="true" class="me-1 inline-block size-2 rounded-full bg-tint align-middle"></span>
                                <span class="sr-only">{{ __('Unread notification') }}:</span>
                            @endunless
                            {{ $item->title }}
                        </h2>

                        @unless ($item->isRead)
                            <x-button.circle icon="check" color="secondary" flat sm
                                             :aria-label="__('Mark as read')"
                                             wire:click="markAsRead('{{ $item->id }}')" />
                        @endunless
                    </div>

                    <div class="text-[0.875rem] text-secondary-label">{!! $item->body !!}</div>

                    <p class="text-[0.75rem] text-secondary-label">
                        <x-ui.localized-date :value="$item->createdAt" />
                    </p>

                    @if ($item->actions !== [])
                        <div class="flex flex-wrap gap-2">
                            @foreach ($item->actions as $index => $action)
                                <x-button :text="$action['label']" sm outline
                                          :href="route('notifications.open', ['notification' => $item->id, 'action' => $index])"
                                          wire:key="notification-{{ $item->id }}-action-{{ $index }}" />
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        {{ $this->notifications->links() }}
    @endif
</div>
