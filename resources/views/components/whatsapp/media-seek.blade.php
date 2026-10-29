{{--
    The seek bar of a mediaPlayer: the played part over a faint track, in
    the current text color, with an invisible range input on top for the
    mouse, touch and keyboard.
--}}
<div {{ $attributes->class('relative h-4 min-w-0 flex-1') }}>
    <div class="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-current/25"></div>
    <div class="absolute top-1/2 left-0 h-1 -translate-y-1/2 rounded-full bg-current" x-bind:style="`width: ${progress}%`"></div>
    <div class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-current" x-bind:style="`left: ${progress}%`"></div>
    <input type="range" min="0" step="0.1" x-bind:max="duration || 0" x-bind:value="current" x-on:input="seek($event)"
           x-bind:disabled="! duration" aria-label="{{ __('Position') }}"
           class="absolute inset-0 m-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-default" />
</div>
