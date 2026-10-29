{{--
    The inbox's conversations, rendered in the `conversations` island of
    the chat screen: the rest of the screen updates without sending the
    list again, and ChatsPage::refreshList() sends it when it changes. An
    island has its own scope, so what it needs comes from the component.
    What a row does goes through Alpine's `$wire` (a `wire:click` in here
    would render the island alone); its menu is the one the screen shares
    (`openMenu`), and the open conversation is marked from `$wire`.
--}}
@php
    $timezone = auth()->user()->timezone;
    $priorityPalette = $this->priorityPalette;
    $bulkMax = \App\Actions\Chats\ApplyChatConversationsBulkAction::MAX_CONVERSATIONS;
@endphp

@if ($this->providers->isEmpty())
    <div class="p-6 text-center text-sm text-secondary-label" data-chat-no-providers>{{ __('No chat channel is switched on for you.') }}</div>
@elseif ($showArchived || $this->archivedCount > 0)
    <button type="button" x-on:click="$wire.$toggle('showArchived')" class="flex w-full items-center gap-6 px-6 py-3 text-left text-[0.9375rem] text-chat-text hover:bg-chat-list-hover focus:outline-none focus-visible:bg-chat-list-hover" data-chat-show-archived>
        <x-icon :name="$showArchived ? 'chevron-left' : 'archive-box'" class="size-5 text-chat-unread" aria-hidden="true" />
        <span class="flex-1">{{ $showArchived ? __('Back to conversations') : __('Archived') }}</span>
        @if (! $showArchived)
            <span class="text-xs font-medium text-chat-unread">{{ $this->archivedCount }}</span>
        @endif
    </button>
@endif

@if ($this->providers->isNotEmpty() && $this->conversations->isEmpty())
    <p class="p-6 text-center text-sm text-secondary-label" data-chat-list-empty>
        {{ filled($search) ? __('No conversations match the search.') : __('No conversations yet. Connect a channel and they show up as they sync.') }}
    </p>
@elseif ($this->conversations->isNotEmpty())
    <ul role="list">
        @foreach ($this->conversations as $item)
            @php
                $itemId = $item->friendly_id;
                $unread = $item->isUnread();
            @endphp
            <li wire:key="conversation-{{ $itemId }}" class="group/item relative">
                <div x-show="! selecting" class="absolute right-3 bottom-3 z-10 opacity-0 transition-opacity group-hover/item:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100 has-[[aria-expanded=true]]:opacity-100">
                    <button type="button" x-tooltip="{{ __('Options') }}" aria-label="{{ __('Options') }}" aria-haspopup="menu"
                            x-on:click.stop="openMenu($el, @js(['id' => $itemId, 'pinned' => $item->isPinned(), 'unread' => $unread, 'archived' => $item->isArchived(), 'muted' => $item->isMuted()]))"
                            x-bind:aria-expanded="(menu?.id === @js($itemId)).toString()"
                            class="grid size-6 place-items-center rounded-full bg-chat-list-hover text-chat-meta hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                            data-chat-item-menu="{{ $itemId }}">
                        <x-icon name="chevron-down" class="size-5" aria-hidden="true" />
                    </button>
                </div>

                <button type="button"
                        x-on:click="if (selecting) { picked.includes(@js($itemId)) ? picked.splice(picked.indexOf(@js($itemId)), 1) : (picked.length < {{ $bulkMax }} && picked.push(@js($itemId))); return } $wire.openConversation(@js($itemId))"
                        x-bind:aria-pressed="selecting ? picked.includes(@js($itemId)).toString() : null"
                        x-bind:class="$wire.conversationId === @js($itemId) ? 'bg-chat-list-active' : 'hover:bg-chat-list-hover focus-visible:bg-chat-list-hover'"
                        data-chat-item="{{ $itemId }}" data-chat-item-provider="{{ $item->provider->slug }}" @if ($unread) data-chat-item-is-unread @endif
                        class="flex w-full items-center gap-3 pl-3 text-left focus:outline-none data-loading:opacity-70">
                    <span x-cloak x-show="selecting" class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors"
                          x-bind:class="picked.includes(@js($itemId)) ? 'border-tint bg-tint text-on-tint' : 'border-chat-meta text-transparent'"
                          data-chat-select-pick="{{ $itemId }}" aria-hidden="true">
                        <x-icon name="check" class="size-3.5" />
                    </span>
                    <span class="relative shrink-0">
                        <x-whatsapp.avatar :name="$item->displayName()" :picture="$item->picture_url" size="lg" />
                        <span class="absolute -right-1 -top-1 grid size-5 place-items-center rounded-full bg-chat-list ring-2 ring-chat-list" data-chat-item-mark="{{ $item->provider->slug }}">
                            <x-chats.provider-icon :provider="$item->provider->slug" :title="$item->provider->name" class="size-3.5" />
                        </span>
                        @if ($item->priority !== null)
                            <span class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full text-[0.6875rem] font-bold text-on-tint ring-2 ring-chat-list"
                                  style="background-color: {{ $item->priorityHex($priorityPalette) }}" data-chat-priority="{{ $itemId }}"
                                  aria-label="{{ __('Priority :level', ['level' => $item->priority]) }}">{{ $item->priority }}</span>
                        @endif
                    </span>

                    <span class="flex min-h-[4.5rem] min-w-0 flex-1 flex-col justify-center border-b border-chat-divider py-2.5 pr-3">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-[1.0625rem] text-chat-text">{{ $item->displayName() }}</span>
                            @if ($item->last_message_at !== null)
                                <span @class(['shrink-0 text-xs', 'text-chat-unread font-medium' => $unread, 'text-chat-meta' => ! $unread])>
                                    {{ $item->last_message_at->setTimezone($timezone)->isToday() ? $item->last_message_at->setTimezone($timezone)->format('H:i') : $item->last_message_at->setTimezone($timezone)->isoFormat('L') }}
                                </span>
                            @endif
                        </span>

                        <span class="flex items-center justify-between gap-2">
                            <span class="flex min-w-0 flex-1 items-center gap-1 text-sm text-chat-meta" data-chat-item-preview>
                                @if ($sort === \App\Livewire\Chats\ChatsPage::SORT_AI && filled($item->ai_summary))
                                    <span class="truncate" data-chat-ai-summary="{{ $itemId }}">{{ $item->ai_summary }}</span>
                                @elseif ($item->last_message !== null)
                                    @if ($item->last_message['from_me'] ?? false)
                                        <span class="shrink-0 font-medium">{{ __('You') }}:</span>
                                    @elseif (filled($item->last_message['sender'] ?? null) && ! $item->isDirect())
                                        <span class="shrink-0 font-medium">{{ $item->last_message['sender'] }}:</span>
                                    @endif
                                    @if (($item->last_message['kind'] ?? 'text') === 'file')
                                        <x-icon name="paper-clip" class="size-4 shrink-0" aria-hidden="true" />
                                    @endif
                                    <span class="truncate">{{ $item->last_message['text'] ?? '' }}</span>
                                @else
                                    <span class="truncate italic">{{ __('No messages yet') }}</span>
                                @endif
                            </span>

                            @if ($item->awaitsAiTriage())
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-chat-panel px-1.5 py-0.5 text-[0.6875rem] text-chat-meta" data-chat-ai-pending="{{ $itemId }}">
                                    <x-icon name="sparkles" class="size-3 animate-pulse" aria-hidden="true" />{{ __('Analyzing…') }}
                                </span>
                            @elseif ($item->aiUrgencySlug() !== null)
                                @php
                                    $aiLook = \App\Models\ChatConversation::AI_URGENCY_LOOKS[$item->aiUrgencySlug()];
                                    $aiHex = \App\Models\WhatsappTarget::PRIORITY_COLORS[$aiLook['color']];
                                    $aiStale = $item->aiTriageIsStale();
                                    $aiTip = trim(($item->ai_summary ?? '').($aiStale ? ' · '.__('Out of date: a message came after the AI read the chat.') : ''), ' ·');
                                @endphp
                                <span @class(['inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-medium', 'opacity-50' => $aiStale])
                                      style="color: {{ $aiHex }}; background-color: {{ $aiHex }}26"
                                      @if ($aiTip !== '') x-tooltip="{{ $aiTip }}" @endif
                                      aria-label="{{ __('AI: :urgency', ['urgency' => __($aiLook['label'])]) }}{{ $aiTip !== '' ? '. '.$aiTip : '' }}"
                                      data-chat-ai-urgency="{{ $itemId }}" data-urgency="{{ $item->aiUrgencySlug() }}" @if ($aiStale) data-chat-ai-stale @endif>
                                    <x-icon name="sparkles" class="size-3" aria-hidden="true" />{{ __($aiLook['label']) }}
                                </span>
                            @endif

                            @foreach ($item->tags->take(3) as $tag)
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $tag->hex() }}" x-tooltip="{{ $tag->name }}" aria-label="{{ $tag->name }}" data-chat-item-tag="{{ $tag->friendly_id }}"></span>
                            @endforeach

                            @if ($item->contact !== null)
                                <x-icon name="user-circle" class="size-4 shrink-0 text-chat-meta" x-tooltip="{{ $item->contact->name }}" data-chat-item-contact="{{ $item->contact->friendly_id }}" />
                            @endif

                            @if ($item->isMuted())
                                <x-icon name="bell-slash" class="size-4 shrink-0 text-chat-meta" data-chat-muted="{{ $itemId }}" :aria-label="__('Muted')" />
                            @endif

                            @if ($item->isPinned())
                                <x-icon name="bookmark" solid class="size-4 shrink-0 text-chat-meta" data-chat-pinned="{{ $itemId }}" :aria-label="__('Pinned')" />
                            @endif

                            {{-- Opening a conversation reads it; the badges go at once, without sending the list again. --}}
                            @if ($item->marked_unread && $item->unread_count === 0)
                                <span x-show="$wire.conversationId !== @js($itemId)" class="size-3 shrink-0 rounded-full bg-chat-unread" data-chat-marked-unread="{{ $itemId }}" aria-label="{{ __('Marked as unread') }}"></span>
                            @endif

                            @if ($item->unread_count > 0)
                                <span x-show="$wire.conversationId !== @js($itemId)" class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-chat-unread px-1.5 text-xs font-semibold text-on-chat-unread" data-chat-unread="{{ $itemId }}"
                                      aria-label="{{ trans_choice('{1} 1 unread message|[2,*] :count unread messages', $item->unread_count, ['count' => $item->unread_count]) }}">{{ $item->unread_count }}</span>
                            @endif
                        </span>
                    </span>
                </button>
            </li>
        @endforeach
    </ul>
@endif
