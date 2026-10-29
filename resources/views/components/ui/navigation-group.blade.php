{{--
    A group of the sidebar (NavigationItem::isGroup()): its entry opens the
    first screen of the group, and its screens are listed under it — open
    while one of them is shown, else on demand. Collapsed to its icons, the
    sidebar shows only the group's entry.

    Props:
    - `item`: the group's NavigationItem.
--}}
@props(['item'])

@php($open = $item->isActive())

<div x-data="{ open: @js($open) }" data-navigation-group="{{ $item->key }}">
    <div class="flex items-center gap-1">
        <x-ui.navigation-link :item="$item" variant="sidebar-group" class="min-w-0 flex-1" />

        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-expanded="{{ $open ? 'true' : 'false' }}"
                aria-controls="navigation-group-{{ $item->key }}" aria-label="{{ __('Show the screens of :group', ['group' => $item->label]) }}"
                class="grid size-8 shrink-0 place-items-center rounded-control text-secondary-label transition-colors hover:bg-fill hover:text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint sidebar-collapsed:hidden"
                data-navigation-group-toggle="{{ $item->key }}">
            <x-icon name="chevron-down" class="size-4 transition-transform motion-reduce:transition-none" x-bind:class="open ? '' : '-rotate-90'" aria-hidden="true" />
        </button>
    </div>

    <ul role="list" id="navigation-group-{{ $item->key }}" x-show="open" @unless ($open) x-cloak @endunless
        class="mt-0.5 ml-5 flex flex-col gap-0.5 border-l border-separator pl-2 sidebar-collapsed:hidden">
        @foreach ($item->children as $child)
            <li><x-ui.navigation-link :item="$child" variant="sidebar-child" /></li>
        @endforeach
    </ul>
</div>
