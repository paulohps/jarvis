@props(['property', 'file' => null, 'kept' => null])

{{--
    The file a scheduled WhatsApp message sends: attach an image, a video or
    a document, or record a voice note in the browser (voiceRecorder), into
    the Livewire upload `property`. `file` is the upload chosen now; `kept`
    the name of the file the message already sends, removed with
    `wire:click` on `removeMedia` (when given).
--}}
<div class="flex flex-col gap-2" x-data="voiceRecorder(@js($property))" data-media-picker>
    @if ($file !== null || $kept !== null)
        @php
            $mime = $file !== null ? (string) $file->getClientMimeType() : '';
            $icon = str_starts_with($mime, 'audio/') ? 'microphone' : (str_starts_with($mime, 'image/') ? 'photo' : 'paper-clip');
        @endphp
        <div class="flex items-center gap-2 rounded-control bg-fill px-3 py-1.5 text-sm text-label" data-media-picker-file>
            <x-icon :name="$icon" class="size-4 shrink-0 text-secondary-label" />
            <span class="min-w-0 flex-1 truncate">{{ $file?->getClientOriginalName() ?? $kept }}</span>
            @if ($file !== null)
                <span class="shrink-0 text-xs text-secondary-label">{{ \Illuminate\Support\Number::fileSize($file->getSize()) }}</span>
            @endif
            <button type="button" class="shrink-0 text-secondary-label hover:text-danger"
                    @if ($file !== null) wire:click="$set('{{ $property }}', null)" @else wire:click="removeMedia" @endif
                    aria-label="{{ __('Remove the file') }}" data-media-picker-remove>
                <x-icon name="x-mark" class="size-4" />
            </button>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-control bg-fill px-3 py-1.5 text-sm text-label hover:bg-fill-pressed" x-show="! recording">
                <x-icon name="paper-clip" class="size-4" />
                {{ __('Attach image, video or document') }}
                <input type="file" wire:model="{{ $property }}" class="sr-only" data-media-picker-attach>
            </label>

            <button type="button" class="inline-flex items-center gap-1.5 rounded-control bg-fill px-3 py-1.5 text-sm text-label hover:bg-fill-pressed" x-show="! recording" x-on:click="start()" data-media-picker-record>
                <x-icon name="microphone" class="size-4" />
                {{ __('Record a voice note') }}
            </button>

            <button type="button" class="inline-flex items-center gap-1.5 rounded-control bg-danger px-3 py-1.5 text-sm font-medium text-on-tint" x-show="recording" x-cloak x-on:click="stop()">
                <span class="size-2 animate-pulse rounded-full bg-on-tint"></span>
                <span x-text="elapsed"></span>
                {{ __('Stop') }}
            </button>
        </div>
    @endif

    <div wire:loading.flex wire:target="{{ $property }}" class="items-center gap-2 text-xs text-secondary-label">{{ __('Uploading…') }}</div>
    <p x-show="failed" x-cloak role="alert" class="text-xs text-danger">{{ __('The browser did not let Jarvis use the microphone.') }}</p>

    @error($property) <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror
</div>
