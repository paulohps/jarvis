@props(['reminders' => false])

{{--
    The menus of the WhatsApp screen, rendered once and shared by every chat
    and message ($store.chatMenu): the options of a chat of the list, the
    options of a message and the reactions. What each offers comes from the
    `data-*` of the button that opened it.
--}}
@php
    $menu = 'fixed z-50 flex flex-col rounded-card bg-secondary-grouped-background p-1.5 text-label shadow-lg ring-1 ring-separator';
    $item = 'flex w-full items-center gap-3 rounded-control px-3 py-2 text-left text-sm hover:bg-fill focus:bg-fill focus:outline-none';
@endphp

<div x-data x-on:click.outside="if (! $event.target.closest('[aria-haspopup=menu]')) $store.chatMenu.close()"
     x-on:keydown.escape.window="$store.chatMenu.close()">
    <div x-show="$store.chatMenu.is('chat')" x-cloak role="menu" x-bind:style="$store.chatMenu.style" class="{{ $menu }} w-56" data-chat-shared-menu="chat">
        <button type="button" role="menuitem" class="{{ $item }}" data-chat-archive
                x-on:click="$wire.archiveChat($store.chatMenu.data.id, ! $store.chatMenu.flag('archived')); $store.chatMenu.close()">
            <x-icon name="archive-box" class="size-5 text-secondary-label" aria-hidden="true" />
            <span x-text="$store.chatMenu.flag('archived') ? @js(__('Unarchive')) : @js(__('Archive'))"></span>
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" data-chat-mark-unread
                x-on:click="$wire.markChatUnread($store.chatMenu.data.id, ! $store.chatMenu.flag('unread')); $store.chatMenu.close()">
            <x-icon name="envelope" class="size-5 text-secondary-label" aria-hidden="true" />
            <span x-text="$store.chatMenu.flag('unread') ? @js(__('Mark as read')) : @js(__('Mark as unread'))"></span>
        </button>
        <button type="button" role="menuitem" class="{{ $item }}"
                x-on:click="$wire.togglePin($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="bookmark" class="size-5 text-secondary-label" aria-hidden="true" />
            <span x-text="$store.chatMenu.flag('pinned') ? @js(__('Unpin')) : @js(__('Pin'))"></span>
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.flag('muted')" data-chat-unmute
                x-on:click="$wire.muteChat($store.chatMenu.data.id, null); $store.chatMenu.close()">
            <x-icon name="bell" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Unmute') }}
        </button>
        @foreach (\App\Actions\Whatsapp\MuteWhatsappChat::DURATIONS as $duration => $label)
            <button type="button" role="menuitem" class="{{ $item }}" x-show="! $store.chatMenu.flag('muted')" data-chat-mute="{{ $duration }}"
                    x-on:click="$wire.muteChat($store.chatMenu.data.id, '{{ $duration }}'); $store.chatMenu.close()">
                <x-icon name="bell-slash" class="size-5 text-secondary-label" aria-hidden="true" />{{ __($label) }}
            </button>
        @endforeach
    </div>

    <div x-show="$store.chatMenu.is('message')" x-cloak role="menu" x-bind:style="$store.chatMenu.style" class="{{ $menu }} w-60" data-chat-shared-menu="message">
        <button type="button" role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.flag('reply')" data-chat-reply
                x-on:click="$wire.reply($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="arrow-uturn-left" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Reply') }}
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.flag('forward')" data-chat-forward
                x-on:click="$wire.startForward($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="arrow-uturn-right" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Forward') }}
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" data-chat-star
                x-on:click="$wire.toggleStar($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="star" class="size-5 text-secondary-label" aria-hidden="true" />
            <span x-text="$store.chatMenu.flag('starred') ? @js(__('Unstar')) : @js(__('Star'))"></span>
        </button>
        <button type="button" role="menuitem" class="{{ $item }}" data-chat-select
                x-on:click="$wire.toggleSelected($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="sparkles" class="size-5 text-secondary-label" aria-hidden="true" />
            <span x-text="$store.chatMenu.flag('picked') ? @js(__('Unpick for the AI answer')) : @js(__('Pick for the AI answer'))"></span>
        </button>
        @if ($reminders)
            <button type="button" role="menuitem" class="{{ $item }}" data-chat-reminder-message
                    x-on:click="$wire.startReminder($store.chatMenu.data.id); $store.chatMenu.close()">
                <x-icon name="bell-alert" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Reminder about this message') }}
            </button>
        @endif
        <button type="button" role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.flag('info')" data-chat-message-info
                x-on:click="$wire.openMessageInfo($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="information-circle" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Message info') }}
        </button>
        <a role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.data.download" x-bind:href="$store.chatMenu.data.download" download
           x-on:click="$store.chatMenu.close()">
            <x-icon name="arrow-down-tray" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Download to the computer') }}
        </a>
        <button type="button" role="menuitem" class="{{ $item }}" x-show="$store.chatMenu.flag('edit')"
                x-on:click="$wire.startEdit($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="pencil" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Edit') }}
        </button>
        <div x-show="$store.chatMenu.flag('delete')" class="my-1 border-t border-separator"></div>
        <button type="button" role="menuitem" class="{{ $item }} text-danger" x-show="$store.chatMenu.flag('delete')"
                x-on:click="$wire.confirmDeleteForEveryone($store.chatMenu.data.id); $store.chatMenu.close()">
            <x-icon name="trash" class="size-5" aria-hidden="true" />{{ __('Delete for everyone') }}
        </button>
    </div>

    <div x-show="$store.chatMenu.is('react')" x-cloak x-bind:style="$store.chatMenu.style" class="{{ $menu }}" data-chat-shared-menu="react"
         x-data="{ more: false }" x-effect="if (! $store.chatMenu.is('react')) more = false"
         x-on:emoji-picked="$store.chatMenu.react($wire, $event.detail.emoji)"
         x-bind:class="more ? 'p-2' : 'rounded-full! p-1!'">
        <div class="flex items-center gap-0.5" x-show="! more">
            @foreach (['👍', '❤️', '😂', '😮', '😢', '🙏'] as $quickEmoji)
                <button type="button" class="grid size-9 place-items-center rounded-full text-xl transition-transform hover:scale-125 hover:bg-fill"
                        x-on:click="$store.chatMenu.react($wire, @js($quickEmoji))" aria-label="{{ $quickEmoji }}"
                        data-chat-react="{{ $quickEmoji }}">{{ $quickEmoji }}</button>
            @endforeach
            <button type="button" class="grid size-9 place-items-center rounded-full text-secondary-label hover:bg-fill"
                    x-on:click="more = true; $nextTick(() => $store.chatMenu.place())" aria-label="{{ __('More emojis') }}">
                <x-icon name="plus" class="size-5" aria-hidden="true" />
            </button>
        </div>
        <template x-if="more">
            <x-whatsapp.emoji-grid />
        </template>
    </div>
</div>
