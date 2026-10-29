@props(['target' => null, 'name' => null, 'picture' => null, 'size' => 'md'])

@php
    $isGroup = $target?->isGroup() ?? false;
    $label = $target?->displayName() ?? (string) $name;
    $picture = $target?->picture_url ?? $picture;
@endphp

{{--
    The round avatar of a chat (`target`) or of who sent a group message
    (`name` and `picture`): the WhatsApp picture, or, without one or once
    the picture's URL has expired, the first letter of the name (a group
    icon for a group). `size`: `lg` (44px) in the list, `md` (40px) in the
    header, `sm` (32px) beside a group message.
--}}
<span aria-hidden="true" x-data="{ failed: false }"
    {{ $attributes->class([
        'relative grid shrink-0 place-items-center overflow-hidden rounded-full bg-fill font-semibold text-secondary-label',
        'size-11 text-base' => $size === 'lg',
        'size-10' => $size === 'md',
        'size-8 text-sm' => $size === 'sm',
    ]) }}>
    @if ($isGroup)
        <x-icon name="user-group" @class(['size-6' => $size === 'lg', 'size-5' => $size !== 'lg']) />
    @else
        {{ mb_strtoupper(mb_substr(ltrim($label, '+'), 0, 1)) }}
    @endif

    @if (filled($picture))
        <img src="{{ $picture }}" alt="" loading="lazy" referrerpolicy="no-referrer"
             x-show="! failed" x-on:error="failed = true"
             class="absolute inset-0 size-full object-cover" data-chat-picture />
    @endif
</span>
