{{--
    A row of color swatches (Workspace::COLORS) as radios bound to `$model`.
    `$allowNone` adds a first choice, '' — "the workspace's color" — drawn in
    `$noneColor`.
--}}
@php
    $allowNone ??= false;
    $live ??= false;
    $noneColor ??= null;
@endphp

<fieldset class="flex flex-wrap items-center gap-2" data-color-picker="{{ $name }}">
    <legend class="sr-only">{{ $legend }}</legend>

    @if ($allowNone)
        <label class="cursor-pointer" title="{{ __('Workspace color') }}">
            <input type="radio" name="{{ $name }}" value="" @if ($live) wire:model.live="{{ $model }}" @else wire:model="{{ $model }}" @endif class="peer sr-only">
            <span class="grid size-7 place-items-center rounded-full ring-1 ring-separator ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint">
                <span class="size-3.5 rounded-full" style="background-color: {{ \App\Models\Workspace::hexOf($noneColor) }}"></span>
            </span>
            <span class="sr-only">{{ __('Workspace color') }}</span>
        </label>
    @endif

    @foreach (\App\Models\Workspace::COLORS as $colorName => $hex)
        <label class="cursor-pointer" title="{{ __(ucfirst($colorName)) }}" wire:key="{{ $name }}-{{ $colorName }}">
            <input type="radio" name="{{ $name }}" value="{{ $colorName }}" @if ($live) wire:model.live="{{ $model }}" @else wire:model="{{ $model }}" @endif class="peer sr-only">
            <span class="block size-7 rounded-full ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint"
                  style="background-color: {{ $hex }}"></span>
            <span class="sr-only">{{ __(ucfirst($colorName)) }}</span>
        </label>
    @endforeach
</fieldset>
