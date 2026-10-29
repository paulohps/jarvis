@php
    $dialogId = 'confirm-dialog-'.Str::random(8);
@endphp

<div x-data="{ open: false }" x-on:keydown.escape.window="open = false" {{ $attributes->whereDoesntStartWith(['wire:', 'x-on:', '@'])->class('inline-flex') }}>
    @isset($trigger)
        <div x-on:click="open = true" class="contents">{{ $trigger }}</div>
    @endisset

    <template x-teleport="body">
        <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-6">
            <div x-show="open" x-transition.opacity x-on:click="open = false" class="absolute inset-0 bg-overlay"></div>

            <div x-show="open"
                 x-trap.inert.noscroll="open"
                 x-transition:enter="transition duration-200 ease-out"
                 x-transition:enter-start="scale-110 opacity-0"
                 x-transition:enter-end="scale-100 opacity-100"
                 x-transition:leave="transition duration-150 ease-in"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 role="alertdialog"
                 aria-modal="true"
                 aria-labelledby="{{ $dialogId }}-title"
                 @if (filled($description)) aria-describedby="{{ $dialogId }}-description" @endif
                 class="glass-thick glass-edge relative w-full max-w-xs overflow-hidden rounded-sheet text-center">
                <div class="flex flex-col gap-1 px-5 pt-5 pb-4">
                    <h2 id="{{ $dialogId }}-title" class="text-[1.0625rem] font-semibold text-label">{{ $title }}</h2>

                    @if (filled($description))
                        <p id="{{ $dialogId }}-description" class="text-[0.8125rem] text-pretty text-label">{{ $description }}</p>
                    @endif

                    {{ $slot }}
                </div>

                <div class="grid grid-cols-2 border-t border-separator">
                    <button type="button"
                            x-on:click="open = false"
                            class="min-h-11 border-r border-separator px-3 text-[1.0625rem] text-tint hover:bg-fill focus:outline-none focus-visible:bg-fill">
                        {{ $cancelLabel }}
                    </button>

                    <button type="button"
                            {{ $attributes->whereStartsWith(['wire:', 'x-on:', '@']) }}
                            x-on:click="open = false"
                            @class([
                                'min-h-11 px-3 text-[1.0625rem] font-semibold hover:bg-fill focus:outline-none focus-visible:bg-fill',
                                'text-danger' => $danger,
                                'text-tint' => ! $danger,
                            ])>
                        {{ $confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
