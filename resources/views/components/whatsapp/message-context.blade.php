@props(['message', 'imageBubble' => false])

{{--
    What sits above the content of a chat message, as on WhatsApp: the
    "Forwarded" label and the message it answers (who wrote it and what it
    said), which, when kept, shows it in the chat on click.
--}}
@if ($message->is_forwarded)
    <p @class(['flex items-center gap-1 text-xs italic opacity-75', 'px-2 pt-0.5' => $imageBubble]) data-chat-forwarded>
        <x-icon name="arrow-uturn-right" class="size-3.5" aria-hidden="true" />
        {{ __('Forwarded') }}
    </p>
@endif

@if ($message->quoted !== null)
    @php
        $quotedBody = $message->quotedMessage?->bodyWithMentions() ?? $message->quoted['body'] ?? null;
        $quotedType = $message->quotedMessage?->message_type ?? $message->quoted['type'] ?? null;
        $quotedKind = \App\Models\WhatsappChatMessage::MEDIA_KINDS[$quotedType] ?? null;
    @endphp
    <button type="button"
            @if ($message->quotedMessage !== null) wire:click="showMessage('{{ $message->quotedMessage->friendly_id }}')" @else disabled @endif
            @class([
                'mb-1 flex w-full min-w-0 flex-col rounded-[0.4rem] border-l-4 border-chat-link bg-fill px-2 py-1 text-left enabled:hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint',
                'mx-0 mt-0.5' => $imageBubble,
            ])
            data-chat-quoted="{{ $message->quotedMessage?->friendly_id ?? '' }}">
        <span class="truncate text-xs font-semibold text-chat-link">{{ $message->quotedSenderLabel() }}</span>
        <span class="line-clamp-2 text-[0.8125rem] opacity-80">
            @if ($quotedKind !== null)
                <x-icon :name="match ($quotedKind) { 'image' => 'photo', 'video' => 'film', 'audio' => 'microphone', default => 'document' }" class="mr-0.5 inline size-3.5 align-[-2px]" aria-hidden="true" />
            @endif
            {{ $quotedBody ?? match ($quotedKind) { 'image' => __('Image'), 'video' => __('Video'), 'audio' => __('Audio'), 'document' => __('Document'), default => __('Message') } }}
        </span>
    </button>
@endif
