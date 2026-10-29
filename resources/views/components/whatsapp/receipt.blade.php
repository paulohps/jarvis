@props(['receipt'])

@php
    use App\Models\WhatsappChatMessage;

    [$label, $ticks] = match (true) {
        $receipt === WhatsappChatMessage::RECEIPT_FAILED => [__('Not sent'), 0],
        $receipt === null, $receipt === WhatsappChatMessage::RECEIPT_PENDING => [__('Sending…'), 0],
        $receipt === WhatsappChatMessage::RECEIPT_SENT => [__('Sent'), 1],
        $receipt === WhatsappChatMessage::RECEIPT_DELIVERED => [__('Delivered'), 2],
        default => [__('Read'), 2],
    };
    $read = $receipt !== null && $receipt >= WhatsappChatMessage::RECEIPT_READ;
@endphp

{{--
    The ticks of a message the number sent, as WhatsApp shows them: a clock
    while waiting, one tick sent, two delivered, two blue read (or played),
    and an alert when it failed.
--}}
<span x-tooltip="{{ $label }}" aria-label="{{ $label }}" data-chat-receipt="{{ $receipt ?? 'pending' }}"
    {{ $attributes->class(['inline-flex shrink-0 items-center', 'text-receipt-read' => $read]) }}>
    @if ($receipt === WhatsappChatMessage::RECEIPT_FAILED)
        <x-icon name="exclamation-circle" class="size-4 text-danger" aria-hidden="true" />
    @elseif ($ticks === 0)
        <x-icon name="clock" class="size-3.5" aria-hidden="true" />
    @else
        <svg viewBox="0 0 18 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
             @class(['h-3 w-4' => $ticks === 2, 'h-3 w-3' => $ticks === 1]) aria-hidden="true">
            @if ($ticks === 2)
                <path d="M1 6.5 4.5 10 11 2" />
                <path d="M7.5 9.2 8.3 10 14.8 2" />
            @else
                <path d="M1.5 6.5 5 10 11.5 2" />
            @endif
        </svg>
    @endif
</span>
