@props(['message'])

@php
    $src = $message->media_path !== null ? route('whatsapp.chat.media', $message) : null;
    $bars = $message->waveformBars();
@endphp

{{--
    A voice note or audio, played as on WhatsApp (mediaPlayer): a round play
    button, the waveform WhatsApp sent (the bars fill as it plays, and a
    click or drag seeks), the length before it plays and the time while it
    does, and the speed (1×, 1.5×, 2×). A file not in Jarvis yet is loaded
    by the first play. Takes the color of the bubble's text.
--}}
<div x-data="mediaPlayer({ seconds: @js($message->media_seconds), load: @js($src === null ? $message->friendly_id : null) })"
    @if ($src === null) data-chat-load="{{ $message->friendly_id }}" @endif
    {{ $attributes->class('my-1 flex w-72 max-w-full items-center gap-3') }}>
    <audio x-ref="media" @if ($src !== null) src="{{ $src }}" @endif preload="metadata" class="hidden"></audio>

    <button type="button" x-on:click="toggle()"
            x-bind:aria-label="playing ? @js(__('Pause')) : @js(__('Play'))"
            class="grid size-11 shrink-0 place-items-center rounded-full bg-current/15 transition-colors hover:bg-current/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-current">
        <x-icon name="arrow-path" class="size-5 animate-spin" x-show="waiting" x-cloak aria-hidden="true" />
        <x-icon name="pause" solid class="size-6" x-show="playing && ! waiting" x-cloak aria-hidden="true" />
        <x-icon name="play" solid class="size-6 translate-x-px" x-show="! playing && ! waiting" aria-hidden="true" />
    </button>

    <div class="flex min-w-0 flex-1 flex-col gap-1">
        <div class="relative flex h-8 items-center gap-[2px]" data-chat-waveform>
            @foreach ($bars as $index => $height)
                <span class="w-[3px] flex-1 rounded-full bg-current transition-opacity"
                      x-bind:class="played({{ $index }}, {{ count($bars) }}) ? 'opacity-100' : 'opacity-35'"
                      style="height: {{ $height }}%"></span>
            @endforeach
            <input type="range" min="0" step="0.1" x-bind:max="duration || 0" x-bind:value="current" x-on:input="seek($event)"
                   x-bind:disabled="! duration || load" aria-label="{{ __('Position') }}"
                   class="absolute inset-0 m-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-default" />
        </div>
        <div class="flex items-center justify-between text-[0.6875rem] tabular-nums opacity-80">
            <span x-text="failed ? @js(__('Could not play')) : time">{{ $message->media_seconds !== null ? sprintf('%d:%02d', intdiv($message->media_seconds, 60), $message->media_seconds % 60) : '0:00' }}</span>
            <button type="button" x-on:click="cycleRate()" x-text="rateLabel" aria-label="{{ __('Playback speed') }}"
                    x-show="playing || rate !== 1" x-cloak
                    class="rounded-full bg-current/15 px-1.5 font-semibold hover:bg-current/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-current">1×</button>
        </div>
    </div>
</div>
