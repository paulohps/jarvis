@php
    $url = $item->url();
    $active = $item->isActive();
    $tag = $url !== null ? 'a' : 'span';
@endphp

<{{ $tag }} @if ($url !== null) href="{{ $url }}" @else aria-disabled="true" @endif
    @if ($active) aria-current="page" @endif
    data-navigation-item="{{ $item->key }}"
    @if ($variant !== 'tab') title="{{ $item->label }}" @endif
    {{ $attributes->class([
        'transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
        'flex items-center gap-3 rounded-control px-3 py-2 text-sm font-medium sidebar-collapsed:justify-center sidebar-collapsed:px-0' => in_array($variant, ['sidebar', 'sidebar-group'], true),
        'bg-tint-fill text-on-tint' => $variant === 'sidebar' && $active,
        'text-label hover:bg-fill' => in_array($variant, ['sidebar', 'sidebar-group'], true) && ! $active && $url !== null,
        // A group whose screen is shown: its entry below is the one filled.
        'text-tint hover:bg-fill sidebar-collapsed:bg-tint-fill sidebar-collapsed:text-on-tint' => $variant === 'sidebar-group' && $active,
        'flex items-center gap-2.5 rounded-control px-3 py-1.5 text-sm' => $variant === 'sidebar-child',
        'bg-tint-fill font-medium text-on-tint' => $variant === 'sidebar-child' && $active,
        'text-secondary-label hover:bg-fill hover:text-label' => $variant === 'sidebar-child' && ! $active && $url !== null,
        'flex min-w-0 flex-col items-center gap-0.5 rounded-full px-1 pt-1.5 pb-1 text-[10px] font-medium leading-tight' => $variant === 'tab',
        'bg-fill text-tint' => $variant === 'tab' && $active,
        'text-secondary-label hover:text-label' => $variant === 'tab' && ! $active && $url !== null,
        'cursor-default text-secondary-label opacity-60' => $url === null,
    ]) }}>
    <x-icon :name="$item->icon" :class="Arr::toCssClasses(['shrink-0', 'size-5' => in_array($variant, ['sidebar', 'sidebar-group'], true), 'size-4' => $variant === 'sidebar-child', 'size-6' => $variant === 'tab'])" aria-hidden="true" />
    <span @class(['truncate', 'max-w-full' => $variant === 'tab', 'sidebar-collapsed:sr-only' => in_array($variant, ['sidebar', 'sidebar-group'], true)])>{{ $item->label }}</span>
</{{ $tag }}>
