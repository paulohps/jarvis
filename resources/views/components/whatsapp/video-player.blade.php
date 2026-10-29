@props(['message'])

@php
    $src = $message->media_path !== null ? route('whatsapp.chat.media', $message) : null;
    $poster = $message->thumbnailUri();
@endphp

{{--
    A video, played in the bubble (mediaPlayer): the preview WhatsApp sent
    and its length while stopped, under a big play button, and a bar with
    play and pause, the seek bar, the time, the speed, sound and full
    screen, which fades while it plays until the pointer comes over it.
    A file not in Jarvis yet is loaded by the first play; a double click
    goes full screen.
--}}
<div x-data="mediaPlayer({ seconds: @js($message->media_seconds), load: @js($src === null ? $message->friendly_id : null) })"
    @if ($src === null) data-chat-load="{{ $message->friendly_id }}" @endif x-ref="frame"
    {{ $attributes->class('group/video relative my-1 min-h-40 overflow-hidden rounded-control bg-media-backdrop text-on-tint') }}>
    <video x-ref="media" @if ($src !== null) src="{{ $src }}#t=0.1" @endif @if ($poster !== null) poster="{{ $poster }}" @endif
           preload="metadata" playsinline x-on:click="toggle()" x-on:dblclick="fullscreen()"
           class="mx-auto block max-h-80 min-h-40 w-full cursor-pointer object-contain"></video>

    <span x-show="! playing && current === 0" class="absolute top-2 left-2 rounded-full bg-media-scrim px-2 py-0.5 text-xs font-semibold tabular-nums"
          x-text="time">{{ $message->media_seconds !== null ? sprintf('%d:%02d', intdiv($message->media_seconds, 60), $message->media_seconds % 60) : '' }}</span>

    <button type="button" x-show="! playing" x-on:click="toggle()" aria-label="{{ __('Play') }}"
            class="absolute inset-0 m-auto grid size-14 place-items-center rounded-full bg-media-scrim transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-on-tint">
        <x-icon name="arrow-path" class="size-7 animate-spin" x-show="waiting" x-cloak aria-hidden="true" />
        <x-icon name="play" solid class="size-7 translate-x-0.5" x-show="! waiting" aria-hidden="true" />
    </button>

    <div x-bind:class="playing ? 'opacity-0 group-hover/video:opacity-100 focus-within:opacity-100' : 'opacity-100'"
         class="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-linear-to-t from-media-scrim to-transparent px-3 pt-6 pb-2 transition-opacity">
        <button type="button" x-on:click="toggle()" x-bind:aria-label="playing ? @js(__('Pause')) : @js(__('Play'))"
                class="grid size-8 shrink-0 place-items-center rounded-full hover:bg-on-tint/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-on-tint">
            <x-icon name="pause" solid class="size-5" x-show="playing" x-cloak aria-hidden="true" />
            <x-icon name="play" solid class="size-5" x-show="! playing" aria-hidden="true" />
        </button>

        <span class="shrink-0 text-xs tabular-nums" x-text="failed ? @js(__('Could not play')) : time">0:00</span>
        <x-whatsapp.media-seek x-bind:class="load && 'pointer-events-none opacity-60'" />

        <button type="button" x-on:click="cycleRate()" x-text="rateLabel" aria-label="{{ __('Playback speed') }}"
                class="shrink-0 rounded-full px-1.5 text-xs font-semibold hover:bg-on-tint/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-on-tint">1×</button>
        <button type="button" x-on:click="toggleMute()" x-bind:aria-label="muted ? @js(__('Turn the sound on')) : @js(__('Mute'))"
                class="grid size-8 shrink-0 place-items-center rounded-full hover:bg-on-tint/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-on-tint">
            <x-icon name="speaker-x-mark" class="size-5" x-show="muted" x-cloak aria-hidden="true" />
            <x-icon name="speaker-wave" class="size-5" x-show="! muted" aria-hidden="true" />
        </button>
        <button type="button" x-on:click="fullscreen()" aria-label="{{ __('Full screen') }}"
                class="grid size-8 shrink-0 place-items-center rounded-full hover:bg-on-tint/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-on-tint">
            <x-icon name="arrows-pointing-out" class="size-5" aria-hidden="true" />
        </button>
    </div>
</div>
