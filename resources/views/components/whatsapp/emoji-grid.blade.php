{{--
    The emoji picker (emojiPicker): the recent ones, then each group behind
    a tab, in a scrolling grid. A pick dispatches `emoji-picked` with
    `{ emoji }`; listen on this element or an ancestor.
--}}
<div x-data="emojiPicker()" {{ $attributes->class(['flex w-72 flex-col gap-2']) }} data-emoji-grid>
    <div class="flex gap-0.5 border-b border-separator pb-1" role="tablist" aria-label="{{ __('Emoji groups') }}">
        <button type="button" role="tab" x-show="recent.length > 0" x-on:click="group = 'recent'" x-bind:aria-selected="group === 'recent'"
                x-bind:class="group === 'recent' ? 'bg-fill' : ''" class="grid size-8 place-items-center rounded-control text-secondary-label hover:bg-fill"
                aria-label="{{ __('Recent') }}">
            <x-icon name="clock" class="size-4" aria-hidden="true" />
        </button>
        <template x-for="tab in groups" x-bind:key="tab.key">
            <button type="button" role="tab" x-on:click="group = tab.key" x-bind:aria-selected="group === tab.key"
                    x-bind:class="group === tab.key ? 'bg-fill' : ''" class="grid size-8 place-items-center rounded-control text-lg hover:bg-fill"
                    x-text="tab.icon"></button>
        </template>
    </div>

    <div class="grid max-h-56 grid-cols-8 gap-0.5 overflow-y-auto">
        <template x-for="emoji in shown" x-bind:key="emoji">
            <button type="button" x-on:click="pick(emoji)" class="grid size-8 place-items-center rounded-control text-xl hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                    x-text="emoji" x-bind:aria-label="emoji" data-emoji></button>
        </template>
    </div>
</div>
