<span data-status="{{ $status }}"
    {{ $attributes->class([
        'inline-flex items-center gap-1.5 rounded-full bg-secondary-grouped-background px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ring-1 ring-inset',
        'text-success ring-success/40' => $tone === 'success',
        'text-warning ring-warning/40' => $tone === 'warning',
        'text-danger ring-danger/40' => $tone === 'danger',
        'text-tint ring-tint/40' => $tone === 'info',
        'text-secondary-label ring-separator' => $tone === 'neutral',
    ]) }}>
    <span aria-hidden="true" class="size-1.5 rounded-full bg-current"></span>
    {{ $label }}
</span>
