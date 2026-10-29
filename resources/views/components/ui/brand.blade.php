@php
    $tag = $href ? 'a' : 'span';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class('flex items-center gap-2.5 rounded-control text-lg font-bold tracking-tight text-label focus:outline-none focus-visible:ring-2 focus-visible:ring-tint') }}>
    <img src="{{ asset('favicon.svg') }}" alt="" width="32" height="32" class="size-8 shrink-0 rounded-control shadow-soft">
    <span class="sidebar-collapsed:[aside_&]:sr-only">Jarvis</span>
</{{ $tag }}>
