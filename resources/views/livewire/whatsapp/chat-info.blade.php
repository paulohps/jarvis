{{--
    The side panel of an open chat: its details, search in it, its starred
    messages and its media gallery. Picking a message shows it in the chat.
--}}
@php
    $timezone = auth()->user()->timezone;
    $highlight = function (?string $text, string $search): string {
        $escaped = e((string) $text);
        foreach (array_filter(preg_split('/\s+/', mb_trim($search)) ?: []) as $word) {
            $escaped = preg_replace('/('.preg_quote(e($word), '/').')/iu', '<mark class="rounded bg-tint/25 text-label">$1</mark>', $escaped) ?? $escaped;
        }

        return $escaped;
    };
@endphp

<div class="flex flex-col gap-4" data-chat-info="{{ $chatId }}">
    <x-ui.segmented-control name="chat-info-tab" :label="__('Show')" wire:model.live="tab" :value="$tab"
                            :options="[
                                \App\Livewire\Whatsapp\ChatInfo::TAB_DETAILS => __('Details'),
                                \App\Livewire\Whatsapp\ChatInfo::TAB_SEARCH => __('Search'),
                                \App\Livewire\Whatsapp\ChatInfo::TAB_STARRED => __('Starred'),
                                \App\Livewire\Whatsapp\ChatInfo::TAB_MEDIA => __('Media'),
                            ]" data-chat-info-tabs />

    @if ($tab === \App\Livewire\Whatsapp\ChatInfo::TAB_DETAILS)
        @php
            $infoChat = $this->chat;
            $infoRow = 'flex w-full items-center gap-3 px-3 py-2.5 text-left text-sm text-label hover:bg-fill focus:outline-none focus-visible:bg-fill';
        @endphp
        {{-- What Jarvis keeps about the chat, and the ways into its messages, as in WhatsApp Web's contact info. --}}
        <ul role="list" class="divide-y divide-separator overflow-hidden rounded-card bg-secondary-grouped-background ring-1 ring-separator" data-chat-info-rows>
            <li>
                <button type="button" class="{{ $infoRow }}" wire:click="$set('tab', '{{ \App\Livewire\Whatsapp\ChatInfo::TAB_MEDIA }}')" data-chat-info-row="media">
                    <x-icon name="photo" class="size-5 text-secondary-label" aria-hidden="true" />
                    <span class="flex-1">{{ __('Media, documents and links') }}</span>
                    <x-icon name="chevron-right" class="size-4 text-secondary-label" aria-hidden="true" />
                </button>
            </li>
            <li>
                <button type="button" class="{{ $infoRow }}" wire:click="$set('tab', '{{ \App\Livewire\Whatsapp\ChatInfo::TAB_STARRED }}')" data-chat-info-row="starred">
                    <x-icon name="star" class="size-5 text-secondary-label" aria-hidden="true" />
                    <span class="flex-1">{{ __('Starred messages') }}</span>
                    <x-icon name="chevron-right" class="size-4 text-secondary-label" aria-hidden="true" />
                </button>
            </li>
            <li>
                <button type="button" class="{{ $infoRow }}" x-on:click="$dispatch('whatsapp-chat-tool', { tool: 'nickname' })" data-chat-edit-nickname>
                    <x-icon name="identification" class="size-5 text-secondary-label" aria-hidden="true" />
                    <span class="flex-1">{{ __('Nickname') }}</span>
                    <span class="max-w-40 truncate text-secondary-label">{{ $infoChat->nickname ?? __('None') }}</span>
                    <x-icon name="chevron-right" class="size-4 text-secondary-label" aria-hidden="true" />
                </button>
            </li>
            <li>
                <button type="button" class="{{ $infoRow }}" x-on:click="$dispatch('whatsapp-chat-tool', { tool: 'tags' })" data-chat-edit-tags>
                    <x-icon name="tag" class="size-5 text-secondary-label" aria-hidden="true" />
                    <span class="flex-1">{{ __('Tags') }}</span>
                    <span class="flex max-w-40 items-center gap-1 truncate text-secondary-label">
                        @forelse ($infoChat->tags as $tag)
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $tag->hex() }}" aria-label="{{ $tag->name }}"></span>
                        @empty
                            {{ __('None') }}
                        @endforelse
                    </span>
                    <x-icon name="chevron-right" class="size-4 text-secondary-label" aria-hidden="true" />
                </button>
            </li>
            <li>
                <button type="button" class="{{ $infoRow }}" x-on:click="$dispatch('whatsapp-chat-tool', { tool: 'priority' })" data-chat-edit-priority>
                    <x-icon name="flag" class="size-5 text-secondary-label" aria-hidden="true" />
                    <span class="flex-1">{{ __('Priority') }}</span>
                    <span class="text-secondary-label">{{ $infoChat->priority ?? __('None') }}</span>
                    <x-icon name="chevron-right" class="size-4 text-secondary-label" aria-hidden="true" />
                </button>
            </li>
        </ul>

        <livewire:whatsapp.chat-details :chat-id="$chatId" :key="'chat-details-'.$chatId" />
    @elseif ($tab === \App\Livewire\Whatsapp\ChatInfo::TAB_SEARCH)
        <x-ui.search-field name="chat-info-search" :placeholder="__('Search messages')" wire:model.live.debounce.300ms="search" x-init="$nextTick(() => $el.querySelector('input')?.focus())" data-chat-info-search />

        @if (mb_strlen(mb_trim($search)) >= 2)
            @forelse ($this->results as $result)
                <button type="button" wire:key="result-{{ $result->friendly_id }}" wire:click="show('{{ $result->friendly_id }}')"
                        class="flex flex-col gap-0.5 rounded-control px-3 py-2 text-left hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                        data-chat-info-result="{{ $result->friendly_id }}">
                    <span class="text-xs text-secondary-label">
                        {{ $result->from_me ? __('You') : ($result->senderLabel() ?? $this->chat->displayName()) }} · {{ $result->sent_at->setTimezone($timezone)->isoFormat('L LT') }}
                    </span>
                    <span class="line-clamp-2 text-sm text-label">{!! $highlight($result->body, $search) !!}</span>
                </button>
            @empty
                <p class="p-4 text-center text-sm text-secondary-label" data-chat-info-empty>{{ __('No messages found.') }}</p>
            @endforelse
        @else
            <p class="p-4 text-center text-sm text-secondary-label">{{ __('Type at least two letters.') }}</p>
        @endif
    @elseif ($tab === \App\Livewire\Whatsapp\ChatInfo::TAB_STARRED)
        @forelse ($this->starred as $message)
            <div wire:key="starred-{{ $message->friendly_id }}" class="flex items-start gap-2 rounded-control px-3 py-2 hover:bg-fill" data-chat-info-starred="{{ $message->friendly_id }}">
                <button type="button" wire:click="show('{{ $message->friendly_id }}')" class="flex min-w-0 flex-1 flex-col gap-0.5 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                    <span class="text-xs text-secondary-label">
                        {{ $message->from_me ? __('You') : ($message->senderLabel() ?? $this->chat->displayName()) }} · {{ $message->sent_at->setTimezone($timezone)->isoFormat('L LT') }}
                    </span>
                    <span class="line-clamp-3 text-sm text-label">{{ $message->body ?? __('Media') }}</span>
                </button>
                <x-whatsapp.chat-action size="sm" icon="star" solid :title="__('Unstar')" wire:click="unstar('{{ $message->friendly_id }}')" data-chat-info-unstar="{{ $message->friendly_id }}" />
            </div>
        @empty
            <p class="p-4 text-center text-sm text-secondary-label" data-chat-info-empty>{{ __('No starred messages. Star one from its menu.') }}</p>
        @endforelse
    @else
        <x-ui.segmented-control name="chat-info-gallery" :label="__('Kind')" wire:model.live="galleryKind" :value="$galleryKind"
                                :options="[
                                    \App\Models\WhatsappTarget::GALLERY_MEDIA => __('Photos and videos'),
                                    \App\Models\WhatsappTarget::GALLERY_DOCUMENTS => __('Documents'),
                                    \App\Models\WhatsappTarget::GALLERY_LINKS => __('Links'),
                                ]" data-chat-info-gallery-kind />

        @php
            $gallery = $this->gallery;
            $hasMore = $gallery->count() > $galleryLimit;
            $gallery = $gallery->take($galleryLimit);
        @endphp

        @if ($gallery->isEmpty())
            <p class="p-4 text-center text-sm text-secondary-label" data-chat-info-empty>{{ __('Nothing here yet.') }}</p>
        @elseif ($galleryKind === \App\Models\WhatsappTarget::GALLERY_MEDIA)
            <div class="grid grid-cols-3 gap-1" data-chat-info-gallery>
                @foreach ($gallery as $item)
                    <button type="button" wire:key="gallery-{{ $item->friendly_id }}" wire:click="show('{{ $item->friendly_id }}')"
                            class="relative aspect-square overflow-hidden rounded-sm bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                            data-chat-info-gallery-item="{{ $item->friendly_id }}">
                        @if ($item->media_path !== null && $item->mediaKind() === 'image')
                            <img src="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('api.whatsapp.media', now()->addHour(), $item) }}" alt="" loading="lazy" class="size-full object-cover">
                        @elseif ($item->thumbnailUri() !== null)
                            <img src="{{ $item->thumbnailUri() }}" alt="" class="size-full object-cover blur-[1px]">
                        @endif
                        @if ($item->mediaKind() === 'video')
                            <x-icon name="play" solid class="absolute inset-0 m-auto size-6 text-on-tint drop-shadow" aria-hidden="true" />
                        @endif
                        <span class="sr-only">{{ $item->sent_at->setTimezone($timezone)->isoFormat('L LT') }}</span>
                    </button>
                @endforeach
            </div>
        @else
            <ul role="list" class="flex flex-col" data-chat-info-gallery>
                @foreach ($gallery as $item)
                    <li wire:key="gallery-{{ $item->friendly_id }}">
                        <button type="button" wire:click="show('{{ $item->friendly_id }}')"
                                class="flex w-full items-center gap-3 rounded-control px-3 py-2 text-left hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                data-chat-info-gallery-item="{{ $item->friendly_id }}">
                            <x-icon :name="$galleryKind === \App\Models\WhatsappTarget::GALLERY_LINKS ? 'link' : 'document'" class="size-5 shrink-0 text-secondary-label" aria-hidden="true" />
                            <span class="flex min-w-0 flex-col">
                                <span class="truncate text-sm text-label">
                                    @if ($galleryKind === \App\Models\WhatsappTarget::GALLERY_LINKS)
                                        {{ \Illuminate\Support\Str::match('~https?://\S+~', (string) $item->body) ?: $item->body }}
                                    @else
                                        {{ $item->media_name ?? $item->body ?? __('Document') }}
                                    @endif
                                </span>
                                <span class="text-xs text-secondary-label">{{ $item->sent_at->setTimezone($timezone)->isoFormat('L LT') }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($hasMore)
            <x-button :text="__('Show more')" flat sm color="secondary" wire:click="loadMoreGallery" data-chat-info-gallery-more />
        @endif
    @endif
</div>
