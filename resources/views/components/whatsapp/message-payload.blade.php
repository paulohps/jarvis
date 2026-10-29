@props(['message'])

{{--
    The content of a chat message that is no text nor file: a location (a
    link to the map), contacts (name and numbers, each a link to open a
    chat) or a poll (its options with their votes). The Evolution API has
    no way to vote, so the owner votes on the phone and the vote shows here.
--}}
@php
    $payload = $message->payload ?? [];
@endphp

@if (isset($payload['location']))
    @php
        $location = $payload['location'];
        $mapUrl = 'https://www.google.com/maps/search/?api=1&query='.$location['latitude'].','.$location['longitude'];
    @endphp
    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="my-1 flex items-center gap-3 rounded-control bg-fill px-3 py-2 hover:bg-fill-pressed" data-chat-location>
        <x-icon name="map-pin" class="size-7 shrink-0 text-danger" aria-hidden="true" />
        <span class="min-w-0">
            <span class="block truncate text-sm font-medium">{{ $location['name'] ?? (! empty($location['live']) ? __('Live location') : __('Location')) }}</span>
            <span class="block truncate text-xs opacity-75">{{ $location['address'] ?? number_format($location['latitude'], 5).', '.number_format($location['longitude'], 5) }}</span>
        </span>
    </a>
@elseif (isset($payload['contacts']))
    <div class="my-1 flex flex-col gap-1" data-chat-contact>
        @foreach ($payload['contacts'] as $card)
            <div class="flex items-center gap-3 rounded-control bg-fill px-3 py-2">
                <x-icon name="user-circle" class="size-8 shrink-0 opacity-75" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ $card['name'] ?? __('Contact') }}</span>
                    @foreach ($card['numbers'] as $number)
                        <span class="block truncate text-xs opacity-75">{{ $number }}</span>
                    @endforeach
                </span>
                @if (($card['numbers'][0] ?? null) !== null)
                    <x-button :text="__('Message')" xs flat color="secondary" wire:click="startChatWith('{{ $card['numbers'][0] }}')" data-chat-contact-message />
                @endif
            </div>
        @endforeach
    </div>
@elseif (isset($payload['poll']))
    @php
        $tally = $message->pollTally();
        $total = max(1, $message->pollVotes->filter(fn ($vote) => $vote->options !== [])->count());
        $multiple = ($payload['poll']['selectable_count'] ?? 0) !== 1;
    @endphp
    <div class="my-1 flex min-w-56 flex-col gap-2" data-chat-poll="{{ $message->friendly_id }}">
        <p class="text-sm font-semibold">{{ $payload['poll']['name'] }}</p>
        <p class="text-xs opacity-75">{{ $multiple ? __('Select one or more') : __('Select one') }} · {{ __('Vote on the phone') }}</p>
        @foreach ($tally as $option)
            <div class="flex flex-col gap-1" data-chat-poll-option="{{ $option['name'] }}">
                <span class="flex items-center gap-2 text-sm">
                    @if ($option['mine'])
                        <x-icon name="check-circle" solid class="size-4 shrink-0 text-tint" aria-hidden="true" />
                    @else
                        <span class="size-4 shrink-0 border border-current opacity-50 {{ $multiple ? 'rounded-sm' : 'rounded-full' }}" aria-hidden="true"></span>
                    @endif
                    <span class="min-w-0 flex-1 break-words">{{ $option['name'] }}</span>
                    <span class="text-xs tabular-nums opacity-75">{{ $option['votes'] }}</span>
                </span>
                <span class="h-1 overflow-hidden rounded-full bg-fill-pressed" aria-hidden="true">
                    <span class="block h-full rounded-full bg-tint" style="width: {{ round($option['votes'] / $total * 100) }}%"></span>
                </span>
            </div>
        @endforeach
    </div>
@endif
