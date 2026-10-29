@if ($instant)
    <time datetime="{{ $machineReadable() }}" {{ $attributes->class('whitespace-nowrap tabular-nums') }}>{{ $formatted() }}</time>
@else
    <span {{ $attributes->class('text-secondary-label') }}>—</span>
@endif
