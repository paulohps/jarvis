@props(['icon', 'title', 'size' => 'md', 'active' => false, 'solid' => false])

{{--
    An icon button of the WhatsApp screen: a round, labelled hit area with an
    icon in the label color (`active` paints it with the tint), and `title`
    shown on hover right away (TallStackUI's x-tooltip). `size`: `md`
    (40px, 24px icon) for the header and the composer, `sm` (32px, 20px
    icon) beside the messages. Any other attribute (wire:click, type,
    disabled, data-*) goes on the button.
--}}
<button type="button" x-tooltip="{{ $title }}" aria-label="{{ $title }}"
    {{ $attributes->class([
        'grid shrink-0 place-items-center rounded-full transition-colors hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:cursor-not-allowed disabled:opacity-40',
        'size-10' => $size === 'md',
        'size-8 bg-secondary-grouped-background shadow-soft' => $size === 'sm',
        'text-tint' => $active,
        'text-label' => ! $active,
    ]) }}>
    <x-icon :name="$icon" :solid="$solid" @class(['size-6' => $size === 'md', 'size-5' => $size === 'sm']) aria-hidden="true" />
</button>
