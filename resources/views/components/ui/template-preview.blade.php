<section aria-label="{{ $label }}" {{ $attributes->class('flex flex-col gap-2') }}>
    <p class="text-[0.8125rem] font-medium text-secondary-label">{{ $label }}</p>

    @if (! $preview->isValid())
        <ul class="flex flex-col gap-0.5 rounded-control bg-fill px-3 py-2 text-[0.8125rem] text-danger" role="alert">
            @foreach ($preview->errors as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @elseif ($preview->isHtml())
        <div data-format="html" class="rounded-control bg-secondary-grouped-background px-4 py-3 text-[0.9375rem] text-label shadow-soft [&_li]:ml-5 [&_ol]:list-decimal [&_p]:my-2 [&_ul]:list-disc">{!! $preview->output !!}</div>
    @else
        <div data-format="text" class="rounded-control bg-secondary-grouped-background px-4 py-3 text-[0.9375rem] whitespace-pre-line text-label shadow-soft">{{ $preview->output }}</div>
    @endif
</section>
