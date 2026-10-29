{{--
    The balloon a selection opens in a WhatsApp text field, inside an
    element with `x-data="whatsappEditor(…)"`: it formats the selection the
    WhatsApp way (bold, italic, strikethrough, monospace, code, lists and
    quotes).
--}}
<div x-cloak x-show="balloon !== null" x-bind:style="balloon ?? {}" role="toolbar" aria-label="{{ __('Format') }}"
     class="fixed z-50 flex items-center gap-0.5 rounded-full bg-chat-bubble-in p-1 text-chat-text shadow-lg ring-1 ring-separator" data-chat-format>
    @foreach ([
        'bold' => ['B', __('Bold'), 'font-bold'],
        'italic' => ['I', __('Italic'), 'italic font-serif'],
        'strike' => ['S', __('Strikethrough'), 'line-through'],
        'mono' => ['{ }', __('Monospace'), 'font-mono text-xs'],
        'code' => ['</>', __('Inline code'), 'font-mono text-xs'],
        'bullets' => ['•', __('Bulleted list'), 'text-lg'],
        'numbers' => ['1.', __('Numbered list'), 'text-xs font-semibold'],
        'quote' => ['❝', __('Quote'), ''],
    ] as $format => [$formatGlyph, $formatLabel, $formatClass])
        <button type="button" x-on:mousedown.prevent="format('{{ $format }}')" aria-label="{{ $formatLabel }}" x-tooltip="{{ $formatLabel }}"
                class="grid size-9 place-items-center rounded-full text-sm hover:bg-fill {{ $formatClass }}" data-chat-format-option="{{ $format }}">{{ $formatGlyph }}</button>
    @endforeach
</div>
