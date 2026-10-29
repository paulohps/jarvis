{{--
    The screens of the navigation group being shown, as tabs over the page
    (NavigationItem::isGroup()): the one shown is marked, the others are
    links. On a phone, where the tab bar has only the group, they are the
    way between its screens.

    Props:
    - `group`: the group's NavigationItem.
--}}
@props(['group'])

<nav aria-label="{{ $group->label }}" {{ $attributes->class('-mx-4 mb-5 overflow-x-auto px-4 sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0') }} data-section-tabs="{{ $group->key }}">
    <ul role="list" class="flex w-max gap-2">
        @foreach ($group->children as $child)
            @continue(! $child->exists())
            @php($active = $child->isActive())
            <li>
                <a href="{{ $child->url() }}" @if ($active) aria-current="page" @endif data-section-tab="{{ $child->key }}"
                   @class([
                       'inline-flex min-h-9 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                       'bg-tint-fill text-on-tint' => $active,
                       'bg-fill text-label hover:bg-fill-pressed' => ! $active,
                   ])>
                    <x-icon :name="$child->icon" class="size-4 shrink-0" aria-hidden="true" />
                    {{ $child->label }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
