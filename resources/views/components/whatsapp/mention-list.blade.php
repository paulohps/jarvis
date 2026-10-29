@props(['below' => false])

{{--
    The group members matching what follows `@`, inside an element with
    `x-data="whatsappEditor(…)"`: picking one mentions them. Above the
    field, or `below` it.
--}}
<ul x-show="mentioning && filtered().length > 0" x-cloak role="listbox" aria-label="{{ __('Mention') }}"
    {{ $attributes->class([$below ? 'top-full mt-1' : 'bottom-full mb-2', 'absolute left-0 z-30 flex max-h-64 w-72 max-w-full flex-col overflow-y-auto rounded-card bg-secondary-grouped-background p-1.5 shadow-lg ring-1 ring-separator']) }}>
    <template x-for="member in filtered()" x-bind:key="member.number">
        <li>
            <button type="button" role="option" class="flex w-full items-center justify-between gap-3 rounded-control px-3 py-1.5 text-left text-sm hover:bg-fill"
                    x-on:mousedown.prevent="pick(member)" data-chat-mention-option>
                <span class="truncate text-label" x-text="member.name"></span>
                <span class="shrink-0 text-xs text-secondary-label" x-text="'+' + member.number"></span>
            </button>
        </li>
    </template>
</ul>
