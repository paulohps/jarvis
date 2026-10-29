{{--
    The WhatsApp screen, full window: the chats of a number on the left
    (number switcher, search, sort, new chat; pinned on top, with their
    priority), the open chat on the right with its reminders and scheduled
    messages, and the composer (AI draft, schedule, send). Below 768px only
    one pane shows at a time.
--}}
@php
    $timezone = auth()->user()->timezone;
    $chat = $this->chat;
    $shownMessages = $this->hasOlderMessages() ? $this->messages->skip(1) : $this->messages;
    $priorityPalette = $this->priorityPalette;
    $drafted = $chat !== null ? $this->draftedMessages : [];
@endphp

<div class="flex h-full w-full overflow-hidden bg-grouped-background" data-whatsapp-chat-screen
     x-data="{ markSeen() { if ($wire.chatId && document.visibilityState === 'visible') { $wire.sendReadReceipts() } } }"
     x-init="$nextTick(() => markSeen()); document.addEventListener('visibilitychange', () => markSeen());
             $store.whatsappPresence.start(@js(\App\Events\NotificationCreated::channelFor($this->userId)), @js([
                 'typing' => __('typing…'),
                 'typingWho' => __(':who is typing…'),
                 'recording' => __('recording audio…'),
                 'recordingWho' => __(':who is recording audio…'),
                 'online' => __('online'),
                 'lastSeen' => __('last seen :time'),
             ]))"
     x-on:whatsapp-chat-opened.window="markSeen()" x-on:whatsapp-chat-received.window="markSeen()">
    {{-- Chats --}}
    <aside @class([
        'flex w-full shrink-0 flex-col border-r border-chat-divider bg-chat-list text-chat-text md:w-[26rem]',
        'hidden md:flex' => $chat !== null,
    ]) aria-label="{{ __('Chats') }}" x-data="{ selecting: false, picked: [] }">
        {{--
            An island: what happens in the open chat (sending, reacting,
            scrolling back, opening another chat) does not render the list,
            and searching or filtering renders only the list. What changes
            a chat of the list asks for it (Chat::refreshChatList()). Opening
            a chat and switching the number call $wire from Alpine, so they
            render the whole screen; the open chat is marked by Alpine.
        --}}
        @island(name: 'chats')
        @php
            $timezone = auth()->user()->timezone;
            $priorityPalette = $this->priorityPalette;
        @endphp
        <header class="flex flex-col gap-3 px-3 pt-3 pb-2">
            <div class="flex items-center justify-between gap-2">
                <x-ui.brand :href="route('whatsapp.accounts.index')" />

                @if ($this->account !== null)
                    <div class="flex items-center gap-1">
                        <x-whatsapp.chat-action icon="check-circle" :title="__('Select chats')" x-on:click="selecting = ! selecting; picked = []" x-bind:aria-pressed="selecting.toString()" data-chat-select-toggle />
                        <x-whatsapp.chat-action icon="cog-6-tooth" :title="__('Chat settings')" x-on:click="$wire.openSettings()" data-chat-settings />
                        <x-whatsapp.chat-action icon="user-group" :title="__('New group')" x-on:click="$dispatch('whatsapp-new-group')" :disabled="! $this->account->isOpen()" data-chat-new-group-open />
                        <x-whatsapp.chat-action icon="pencil-square" :title="__('New chat')" x-on:click="$wire.set('showNewChat', true)" data-chat-new />
                    </div>
                @endif
            </div>

            @if ($this->accounts->count() > 1)
                <label class="sr-only" for="chat-account">{{ __('Number') }}</label>
                <select id="chat-account" x-on:change="$wire.selectAccount($event.target.value)" data-chat-account
                        class="block min-h-9 w-full rounded-control border-0 bg-fill py-2 pr-8 pl-3 text-[0.9375rem] text-label focus:ring-2 focus:ring-tint focus:outline-none">
                    @foreach ($this->accounts as $option)
                        <option value="{{ $option->friendly_id }}" @selected($option->is($this->account))>
                            {{ $option->nickname }}{{ $option->isOpen() ? '' : ' · '.__('not connected') }}
                        </option>
                    @endforeach
                </select>
            @endif

            @if ($this->account !== null)
                <x-ui.search-field name="search" :placeholder="__('Search by name or number')" wire:model.live.debounce.300ms="search" data-chat-search />

                {{-- The sort and the tags as WhatsApp Web's filter chips. --}}
                @php
                    $chip = 'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-tint';
                    $chipOn = 'bg-chat-bubble-out font-medium text-chat-text';
                    $chipOff = 'bg-chat-panel text-chat-meta hover:bg-chat-list-active';
                @endphp
                <div class="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1" role="group" aria-label="{{ __('Sort chats') }}" data-chat-sort>
                    @foreach ([\App\Livewire\Whatsapp\Chat::SORT_RECENT => __('Recent'), \App\Livewire\Whatsapp\Chat::SORT_PRIORITY => __('Priority'), \App\Livewire\Whatsapp\Chat::SORT_AI => __('AI')] as $sortOption => $sortLabel)
                        <button type="button" wire:click="$set('sort', '{{ $sortOption }}')" aria-pressed="{{ $sort === $sortOption ? 'true' : 'false' }}"
                                class="{{ $chip }} {{ $sort === $sortOption ? $chipOn : $chipOff }}" data-chat-sort-option="{{ $sortOption }}">{{ $sortLabel }}</button>
                    @endforeach
                </div>

                {{--
                    Picking chats to change at once: the picks live in Alpine
                    until an action sends them (Chat::bulkAction(), or
                    Chat::triageChats() for the AI, which takes only the
                    unread ones).
                --}}
                @php
                    $bulkMax = \App\Actions\Whatsapp\ApplyWhatsappChatsBulkAction::MAX_CHATS;
                    $allIds = $this->chats->pluck('friendly_id')->take($bulkMax)->values();
                    $unreadIds = $this->chats->filter->isUnread()->pluck('friendly_id')->take($bulkMax)->values();
                    $bulkActions = [
                        'read' => ['icon' => 'check', 'label' => __('Mark as read')],
                        'unread' => ['icon' => 'envelope', 'label' => __('Mark as unread')],
                        $showArchived ? 'unarchive' : 'archive' => ['icon' => $showArchived ? 'arrow-up-tray' : 'archive-box', 'label' => $showArchived ? __('Unarchive') : __('Archive')],
                        'mute' => ['icon' => 'bell-slash', 'label' => __('Mute')],
                        'unmute' => ['icon' => 'bell', 'label' => __('Unmute')],
                        'clear_priority' => ['icon' => 'x-circle', 'label' => __('Clear my priority')],
                        'clear_ai' => ['icon' => 'x-mark', 'label' => __('Clear AI priority')],
                    ];
                @endphp
                <div x-cloak x-show="selecting" class="flex flex-col gap-2 rounded-control bg-chat-panel px-3 py-2 text-sm" data-chat-bulk-bar>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="min-w-0 flex-1 text-chat-meta"
                              x-text="picked.length === 0 ? @js(__('Pick the chats.')) : @js(__(':count picked (up to :max)', ['max' => $bulkMax])).replace(':count', picked.length)"></span>
                        <button type="button" class="text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="picked = @js($allIds)" data-chat-bulk-all>{{ __('All') }}</button>
                        @if ($unreadIds->isNotEmpty())
                            <button type="button" class="text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="picked = @js($unreadIds)" data-chat-triage-all>{{ __('All unread') }}</button>
                        @endif
                        <button type="button" class="text-chat-meta hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="selecting = false; picked = []">{{ __('Cancel') }}</button>
                    </div>

                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Actions on the chats picked') }}">
                        <button type="button" x-bind:disabled="picked.length === 0"
                                x-on:click="$wire.triageChats(picked).then(() => { selecting = false; picked = [] })"
                                class="inline-flex items-center gap-1 rounded-full bg-tint-fill px-2.5 py-1 text-xs font-medium text-on-tint disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                data-chat-triage-run>
                            <x-icon name="sparkles" class="size-3.5" aria-hidden="true" />{{ __('Prioritize with AI') }}
                        </button>
                        @foreach ($bulkActions as $bulkAction => $look)
                            <button type="button" x-bind:disabled="picked.length === 0" x-on:click="$wire.bulkAction(@js($bulkAction), picked).then(() => { selecting = false; picked = [] })"
                                    class="inline-flex items-center gap-1 rounded-full bg-chat-list-active px-2.5 py-1 text-xs text-chat-text hover:bg-chat-list-hover disabled:opacity-40 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                    data-chat-bulk-action="{{ $bulkAction }}">
                                <x-icon :name="$look['icon']" class="size-3.5" aria-hidden="true" />{{ $look['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($this->tags->isNotEmpty())
                    <div class="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1" role="group" aria-label="{{ __('Filter by tag') }}" data-chat-tag-filter>
                        @foreach ($this->tags as $tag)
                            <button type="button" wire:click="filterByTag('{{ $tag->friendly_id }}')" aria-pressed="{{ $tagFilter === $tag->friendly_id ? 'true' : 'false' }}"
                                    class="{{ $chip }} {{ $tagFilter === $tag->friendly_id ? $chipOn : $chipOff }}"
                                    data-chat-filter-tag="{{ $tag->friendly_id }}">
                                <span class="size-2 rounded-full" style="background-color: {{ $tag->hex() }}"></span>
                                {{ $tag->name }}
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
        </header>

        <div class="flex-1 overflow-y-auto">
            @if ($this->account === null)
                <div class="p-6 text-center text-sm text-secondary-label">
                    {{ __('Connect a number to see its chats here.') }}
                    <a href="{{ route('whatsapp.accounts.connect') }}" class="text-tint hover:underline">{{ __('Connect a number') }}</a>
                </div>
            @endif

            @if ($this->account !== null && ($showArchived || $this->archivedCount > 0))
                <button type="button" wire:click="$toggle('showArchived')" class="flex w-full items-center gap-6 px-6 py-3 text-left text-[0.9375rem] text-chat-text hover:bg-chat-list-hover focus:outline-none focus-visible:bg-chat-list-hover" data-chat-show-archived>
                    <x-icon :name="$showArchived ? 'chevron-left' : 'archive-box'" class="size-5 text-chat-unread" aria-hidden="true" />
                    <span class="flex-1">{{ $showArchived ? __('Back to chats') : __('Archived') }}</span>
                    @if (! $showArchived)
                        <span class="text-xs font-medium text-chat-unread">{{ $this->archivedCount }}</span>
                    @endif
                </button>
            @endif

            @if ($this->account === null)
            @elseif ($this->chats->isEmpty())
                <p class="p-6 text-center text-sm text-secondary-label" data-chat-list-empty>
                    {{ filled($search) ? __('No chats match the search.') : __('No chats yet. They show up as messages come in, or after “Sync conversations”.') }}
                </p>
            @else
                <ul role="list" x-data="{ opening: null }">
                    @foreach ($this->chats as $item)
                        <li wire:key="chat-{{ $item->friendly_id }}" class="group/item relative">
                            <div class="absolute right-3 bottom-3 z-10 opacity-0 transition-opacity group-hover/item:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100 has-[[aria-expanded=true]]:opacity-100" data-chat-item-menu="{{ $item->friendly_id }}">
                                <button type="button" x-tooltip="{{ __('Chat options') }}" aria-label="{{ __('Chat options') }}" aria-haspopup="menu"
                                        x-on:click.stop="$store.chatMenu.show('chat', $el)" x-bind:aria-expanded="($store.chatMenu.anchor === $el).toString()"
                                        data-id="{{ $item->friendly_id }}" data-archived="{{ $item->isArchived() ? 1 : 0 }}" data-unread="{{ $item->isUnread() ? 1 : 0 }}"
                                        data-pinned="{{ $item->isPinned() ? 1 : 0 }}" data-muted="{{ $item->isMuted() ? 1 : 0 }}"
                                        class="grid size-6 place-items-center rounded-full bg-chat-list-hover text-chat-meta hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">
                                    <x-icon name="chevron-down" class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                            <button type="button" data-chat-item="{{ $item->friendly_id }}"
                                    x-on:click="if (selecting) { picked.includes(@js($item->friendly_id)) ? picked.splice(picked.indexOf(@js($item->friendly_id)), 1) : (picked.length < {{ \App\Actions\Whatsapp\ApplyWhatsappChatsBulkAction::MAX_CHATS }} && picked.push(@js($item->friendly_id))); return } opening = @js($item->friendly_id); $wire.openChat(@js($item->friendly_id)).finally(() => opening = null)"
                                    class="flex w-full items-center gap-3 pl-3 text-left focus:outline-none"
                                    x-bind:class="$wire.chatId === @js($item->friendly_id) ? 'bg-chat-list-active' : (opening === @js($item->friendly_id) ? 'bg-chat-list-hover opacity-70' : 'hover:bg-chat-list-hover focus-visible:bg-chat-list-hover')">
                                <span x-cloak x-show="selecting" class="grid size-5 shrink-0 place-items-center rounded-full border-2 transition-colors"
                                      x-bind:class="picked.includes(@js($item->friendly_id)) ? 'border-tint bg-tint text-on-tint' : 'border-chat-meta text-transparent'"
                                      data-chat-select-pick="{{ $item->friendly_id }}" aria-hidden="true">
                                    <x-icon name="check" class="size-3.5" />
                                </span>
                                <span class="relative shrink-0">
                                    <x-whatsapp.avatar :target="$item" size="lg" />

                                    @if ($item->priority !== null)
                                        <span class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full text-[0.6875rem] font-bold text-on-tint ring-2 ring-chat-list"
                                              style="background-color: {{ $item->priorityHex($priorityPalette) }}"
                                              data-chat-priority="{{ $item->friendly_id }}"
                                              aria-label="{{ __('Priority :level', ['level' => $item->priority]) }}">{{ $item->priority }}</span>
                                    @endif
                                </span>

                                <span class="flex min-h-[4.5rem] min-w-0 flex-1 flex-col justify-center border-b border-chat-divider py-2.5 pr-3">
                                    <span class="flex items-baseline justify-between gap-2">
                                        <span class="truncate text-[1.0625rem] text-chat-text">{{ $item->displayName() }}</span>
                                        <span @class(['shrink-0 text-xs', 'text-chat-unread font-medium' => $item->unread_count > 0 || $item->marked_unread, 'text-chat-meta' => $item->unread_count === 0 && ! $item->marked_unread])>
                                            {{ $item->last_message_at->setTimezone($timezone)->isToday()
                                                ? $item->last_message_at->setTimezone($timezone)->format('H:i')
                                                : $item->last_message_at->setTimezone($timezone)->isoFormat('L') }}
                                        </span>
                                    </span>

                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-medium text-chat-unread" x-cloak
                                              x-show="$store.whatsappPresence.active('{{ $item->friendly_id }}')"
                                              x-text="$store.whatsappPresence.text('{{ $item->friendly_id }}')" data-chat-item-presence="{{ $item->friendly_id }}"></span>
                                        @php $lastSummary = $item->lastChatMessage?->summary(); @endphp
                                        <span class="flex min-w-0 flex-1 items-center gap-1 text-sm text-chat-meta" x-show="! $store.whatsappPresence.active('{{ $item->friendly_id }}')" data-chat-item-preview>
                                            @if ($item->lastChatMessage?->from_me)
                                                <x-whatsapp.receipt :receipt="$item->lastChatMessage->receipt" />
                                            @elseif ($item->lastChatMessage?->senderLabel() !== null)
                                                <span class="shrink-0 font-medium">{{ $item->lastChatMessage->senderLabel() }}:</span>
                                            @endif
                                            @if ($sort === \App\Livewire\Whatsapp\Chat::SORT_AI && filled($item->ai_summary))
                                                <span class="truncate" data-chat-ai-summary="{{ $item->friendly_id }}">{{ $item->ai_summary }}</span>
                                            @elseif ($lastSummary !== null)
                                                @if ($lastSummary['icon'] !== null)
                                                    <x-icon :name="$lastSummary['icon']" class="size-4 shrink-0" aria-hidden="true" data-chat-item-preview-icon="{{ $lastSummary['icon'] }}" />
                                                @endif
                                                <span class="truncate">{{ $lastSummary['text'] ?? $lastSummary['label'] }}</span>
                                            @endif
                                        </span>

                                        @if ($item->awaitsAiTriage())
                                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-chat-panel px-1.5 py-0.5 text-[0.6875rem] text-chat-meta" data-chat-ai-pending="{{ $item->friendly_id }}">
                                                <x-icon name="sparkles" class="size-3 animate-pulse" aria-hidden="true" />{{ __('Analyzing…') }}
                                            </span>
                                        @elseif ($item->aiUrgencySlug() !== null)
                                            @php
                                                $aiLook = \App\Models\WhatsappTarget::AI_URGENCY_LOOKS[$item->aiUrgencySlug()];
                                                $aiHex = \App\Models\WhatsappTarget::PRIORITY_COLORS[$aiLook['color']];
                                                $aiStale = $item->aiTriageIsStale();
                                                $aiTip = trim(($item->ai_summary ?? '').($aiStale ? ' · '.__('Out of date: a message came after the AI read the chat.') : ''), ' ·');
                                            @endphp
                                            <span @class(['inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-medium', 'opacity-50' => $aiStale])
                                                  style="color: {{ $aiHex }}; background-color: {{ $aiHex }}26"
                                                  @if ($aiTip !== '') x-tooltip="{{ $aiTip }}" @endif
                                                  aria-label="{{ __('AI: :urgency', ['urgency' => __($aiLook['label'])]) }}{{ $aiTip !== '' ? '. '.$aiTip : '' }}"
                                                  data-chat-ai-urgency="{{ $item->friendly_id }}" data-urgency="{{ $item->aiUrgencySlug() }}" @if ($aiStale) data-chat-ai-stale @endif>
                                                <x-icon name="sparkles" class="size-3" aria-hidden="true" />{{ __($aiLook['label']) }}
                                            </span>
                                        @endif

                                        @foreach ($item->tags->take(3) as $tag)
                                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $tag->hex() }}" x-tooltip="{{ $tag->name }}"
                                                  aria-label="{{ $tag->name }}" data-chat-item-tag="{{ $tag->friendly_id }}"></span>
                                        @endforeach

                                        @if ($item->isMuted())
                                            <x-icon name="bell-slash" class="size-4 shrink-0 text-chat-meta" data-chat-muted="{{ $item->friendly_id }}" :aria-label="__('Muted')" />
                                        @endif

                                        @if ($item->isPinned())
                                            <x-icon name="bookmark" solid class="size-4 shrink-0 text-chat-meta" data-chat-pinned="{{ $item->friendly_id }}" :aria-label="__('Pinned')" />
                                        @endif

                                        @if ($item->marked_unread && $item->unread_count === 0)
                                            <span class="size-3 shrink-0 rounded-full bg-chat-unread" data-chat-marked-unread="{{ $item->friendly_id }}" aria-label="{{ __('Marked as unread') }}"></span>
                                        @endif

                                        @if ($item->unread_count > 0)
                                            <span class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-chat-unread px-1.5 text-xs font-semibold text-on-chat-unread"
                                                  data-chat-unread="{{ $item->friendly_id }}"
                                                  aria-label="{{ trans_choice('{1} 1 unread message|[2,*] :count unread messages', $item->unread_count, ['count' => $item->unread_count]) }}">
                                                {{ $item->unread_count }}
                                            </span>
                                        @endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        @endisland
    </aside>

    {{-- Open chat --}}
    <main @class(['flex min-w-0 flex-1 flex-col bg-chat-wallpaper text-chat-text', 'hidden md:flex' => $chat === null]) id="main-content">
        @if ($chat === null)
            <div class="m-auto flex max-w-sm flex-col items-center gap-3 p-6 text-center">
                <x-icon name="chat-bubble-left-right" class="size-12 text-secondary-label" aria-hidden="true" />
                <p class="text-sm text-secondary-label">{{ __('Pick a chat on the left, or start a new one.') }}</p>
            </div>
        @else
            <header class="relative flex items-center gap-3 border-b border-separator bg-chat-panel px-3 py-2">
                <div wire:loading.delay wire:target.except="__dispatch, $refresh, refreshPicture, typing, sendReadReceipts, loadSenderPictures, text, loadMentionables"
                     class="pointer-events-none absolute inset-x-0 -bottom-px h-0.5 overflow-hidden" role="progressbar" aria-label="{{ __('Working…') }}" data-chat-progress>
                    <div class="chat-progress h-full w-1/3 bg-chat-accent"></div>
                </div>
                <x-whatsapp.chat-action icon="chevron-left" :title="__('Back')" class="md:hidden" wire:click="closeChat" data-chat-back />

                {{-- As on WhatsApp Web: the name and picture open the contact or group info, the rest is in the menu. --}}
                <button type="button" wire:click="openInfo('{{ \App\Livewire\Whatsapp\ChatInfo::TAB_DETAILS }}')"
                        class="-my-1 flex min-w-0 flex-1 items-center gap-3 rounded-control py-1 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                        aria-label="{{ $chat->isGroup() ? __('Group info') : __('Contact info') }}" data-chat-open-details>
                    <x-whatsapp.avatar :target="$chat" wire:key="chat-avatar-{{ $chat->friendly_id }}"
                                       :x-init="\App\Actions\Whatsapp\RefreshWhatsappChatPicture::isDue($chat) ? '$wire.refreshPicture(\''.$chat->friendly_id.'\')' : null" />

                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="flex items-center gap-2 truncate font-semibold text-label" data-chat-title>
                            <span class="truncate">{{ $chat->displayName() }}</span>
                            @if ($chat->priority !== null)
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-bold text-on-tint" style="background-color: {{ $chat->priorityHex($priorityPalette) }}">
                                    {{ __('Priority :level', ['level' => $chat->priority]) }}
                                </span>
                            @endif
                        </span>
                        @php
                            $whatsappName = filled($chat->nickname) ? ($chat->name ?? null) : null;
                            $subtitle = array_filter([
                                $chat->isGroup() ? __('Group') : null,
                                $chat->isBlocked() ? __('Blocked') : null,
                                $chat->ephemeral_seconds > 0 ? __('Disappearing messages: :timer', ['timer' => __(\App\Models\WhatsappTarget::EPHEMERAL_TIMERS[$chat->ephemeral_seconds] ?? '')]) : null,
                                $whatsappName !== null ? __('“:name” on WhatsApp', ['name' => $whatsappName]) : null,
                                ! $chat->isGroup() && (filled($chat->name) || filled($chat->nickname)) ? $chat->phoneNumber() : null,
                            ]);
                        @endphp
                        <span class="block truncate text-xs font-medium text-success" x-cloak
                              x-show="$store.whatsappPresence.text('{{ $chat->friendly_id }}') !== ''"
                              x-text="$store.whatsappPresence.text('{{ $chat->friendly_id }}')" data-chat-presence aria-live="polite"></span>
                        <span class="flex min-w-0 items-center gap-2 truncate text-xs text-secondary-label" data-chat-subtitle
                              x-show="$store.whatsappPresence.text('{{ $chat->friendly_id }}') === ''">
                            @foreach ($chat->tags as $tag)
                                <span class="inline-flex shrink-0 items-center gap-1 font-medium text-label" data-chat-tag="{{ $tag->friendly_id }}">
                                    <span class="size-2 rounded-full" style="background-color: {{ $tag->hex() }}"></span>{{ $tag->name }}
                                </span>
                            @endforeach
                            <span class="truncate">{{ $subtitle !== [] ? implode(' · ', $subtitle) : ($chat->isGroup() ? __('Click here for group info') : __('Click here for contact info')) }}</span>
                        </span>
                    </span>
                </button>

                <div class="flex shrink-0 items-center gap-1">
                    <x-whatsapp.chat-action icon="magnifying-glass" :title="__('Search in the chat')" wire:click="openInfo('{{ \App\Livewire\Whatsapp\ChatInfo::TAB_SEARCH }}')" data-chat-open-search />

                    @php $menuItem = 'flex w-full items-center gap-3 rounded-control px-3 py-2 text-left text-sm hover:bg-fill focus:bg-fill focus:outline-none'; @endphp
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" data-chat-more-actions>
                        <x-whatsapp.chat-action icon="ellipsis-vertical" :title="__('More actions')" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" />
                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" x-on:click="open = false"
                             class="absolute top-full right-0 z-40 mt-1 flex w-64 flex-col rounded-card bg-secondary-grouped-background p-1.5 text-label shadow-lg ring-1 ring-separator">
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="openInfo('{{ \App\Livewire\Whatsapp\ChatInfo::TAB_DETAILS }}')">
                                <x-icon name="information-circle" class="size-5 text-secondary-label" aria-hidden="true" />{{ $chat->isGroup() ? __('Group info') : __('Contact info') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="openInfo('{{ \App\Livewire\Whatsapp\ChatInfo::TAB_STARRED }}')" data-chat-open-starred>
                                <x-icon name="star" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Starred messages') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="openInfo('{{ \App\Livewire\Whatsapp\ChatInfo::TAB_MEDIA }}')" data-chat-open-media>
                                <x-icon name="photo" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Media, documents and links') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="togglePin('{{ $chat->friendly_id }}')" data-chat-pin>
                                <x-icon name="bookmark" class="size-5 text-secondary-label" aria-hidden="true" />{{ $chat->isPinned() ? __('Unpin') : __('Pin') }}
                            </button>
                            @can('access-module', \App\Models\Module::REMINDERS)
                                <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="startReminder" data-chat-reminder>
                                    <x-icon name="bell-alert" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('New reminder') }}
                                </button>
                            @endcan
                            <div class="my-1 border-t border-separator"></div>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="openSettings" data-chat-open-settings>
                                <x-icon name="cog-6-tooth" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Chat settings') }}
                            </button>
                            @if ($this->account?->isOpen())
                                <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="syncChat" data-chat-sync>
                                    <x-icon name="arrow-path" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Sync this chat with WhatsApp') }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </header>

            @if (filled($notice))
                <p role="status" class="bg-success/10 px-4 py-2 text-sm text-success" data-chat-notice>{{ $notice }}</p>
            @endif

            @if (filled($error))
                <p role="alert" class="bg-danger/10 px-4 py-2 text-sm text-danger" data-chat-alert>{{ $error }}</p>
            @endif

            @if ($this->chatReminders->isNotEmpty() || $this->scheduled->isNotEmpty())
                <div class="flex flex-col gap-1 border-b border-separator bg-chat-panel px-4 py-2 text-sm">
                    @foreach ($this->chatReminders as $reminder)
                        @php $due = $reminder->occurrences->first()?->due_at; @endphp
                        <p class="flex items-center gap-2 text-label" wire:key="reminder-{{ $reminder->friendly_id }}" data-chat-reminder-item="{{ $reminder->friendly_id }}">
                            <x-icon name="bell-alert" class="size-4 shrink-0 text-warning" aria-hidden="true" />
                            <span class="truncate">{{ $reminder->title }}</span>
                            @if ($due !== null)
                                <span class="shrink-0 text-xs text-secondary-label">{{ $due->setTimezone($timezone)->isoFormat('lll') }}</span>
                            @endif
                        </p>
                    @endforeach

                    @foreach ($this->scheduled as $scheduledMessage)
                        <div class="flex items-center gap-2 text-label" wire:key="scheduled-{{ $scheduledMessage->friendly_id }}" data-chat-scheduled="{{ $scheduledMessage->friendly_id }}">
                            <x-icon :name="$scheduledMessage->isOnce() ? 'clock' : 'arrow-path'" class="size-4 shrink-0 text-tint" aria-hidden="true" />
                            <span class="shrink-0 text-xs text-secondary-label">{{ $scheduledMessage->recurrence->next_run_at->setTimezone($timezone)->isoFormat('lll') }}</span>
                            <span class="flex min-w-0 flex-1 items-center gap-1 truncate">
                                @if ($scheduledMessage->writesWithAi())
                                    <x-icon name="sparkles" class="size-3.5 shrink-0 text-tint" data-chat-scheduled-ai />
                                    <span class="truncate">{{ $scheduledMessage->ai_prompt }}</span>
                                @elseif ($scheduledMessage->sendsPoll())
                                    <x-icon name="chart-bar" class="size-3.5 shrink-0 text-tint" data-chat-scheduled-poll />
                                    <span class="truncate">{{ $scheduledMessage->body }}</span>
                                @else
                                    @if ($scheduledMessage->media_path !== null)
                                        <x-icon :name="str_starts_with((string) $scheduledMessage->media_mime, 'audio/') ? 'microphone' : (str_starts_with((string) $scheduledMessage->media_mime, 'image/') ? 'photo' : 'paper-clip')" class="size-3.5 shrink-0 text-secondary-label" data-chat-scheduled-media />
                                    @endif
                                    <span class="truncate">{{ filled($scheduledMessage->body) ? $scheduledMessage->body : ($scheduledMessage->media_name ?? __('Voice note')) }}</span>
                                @endif
                            </span>
                            @if ($scheduledMessage->isOnce())
                                <button type="button" class="shrink-0 text-xs text-danger hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                        wire:click="cancelScheduled('{{ $scheduledMessage->friendly_id }}')"
                                        wire:confirm="{{ __('Cancel this scheduled message?') }}"
                                        data-chat-cancel-scheduled="{{ $scheduledMessage->friendly_id }}">{{ __('Cancel') }}</button>
                            @else
                                <a href="{{ route('whatsapp.messages.edit', $scheduledMessage) }}" target="_blank" rel="noopener" class="shrink-0 text-xs text-tint hover:underline">{{ __('Edit') }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{--
                As on WhatsApp Web: opening the chat or sending goes to its end; a
                message that comes in follows only while the user is at the end,
                else the button at the bottom right counts the new ones and goes
                there when clicked.
            --}}
            <div class="relative flex min-h-0 flex-1 flex-col"
                 x-data="{
                    keep: null,
                    atBottom: true,
                    unseen: 0,
                    lastId: null,
                    init() {
                        this.toBottom();
                        this.$nextTick(() => this.lastId = this.lastMessageId());
                    },
                    scroller() { return this.$refs.scroller },
                    onScroll() {
                        const box = this.scroller();
                        this.atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80;
                        if (this.atBottom) { this.unseen = 0 }
                    },
                    lastMessageId() {
                        const items = this.scroller().querySelectorAll('[data-chat-message]');
                        return items.length ? items[items.length - 1].dataset.chatMessage : null;
                    },
                    toBottom(smooth = false) {
                        this.$nextTick(() => requestAnimationFrame(() => {
                            const box = this.scroller();
                            box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
                            this.unseen = 0;
                            this.atBottom = true;
                            this.lastId = this.lastMessageId();
                        }));
                    },
                    received() {
                        const following = this.atBottom;
                        this.$nextTick(() => requestAnimationFrame(() => {
                            if (following) { this.toBottom(); return }
                            let after = this.lastId === null;
                            for (const item of this.scroller().querySelectorAll('[data-chat-message]')) {
                                if (after && item.dataset.fromMe === 'false') { this.unseen++ }
                                if (item.dataset.chatMessage === this.lastId) { after = true }
                            }
                            this.lastId = this.lastMessageId();
                        }));
                    },
                    scrollTo(id) {
                        setTimeout(() => {
                            const item = this.scroller().querySelector(`[data-chat-message='${id}']`);
                            if (! item) { return; }
                            item.scrollIntoView({ block: 'center', behavior: 'smooth' });
                            item.classList.add('chat-flash');
                            setTimeout(() => item.classList.remove('chat-flash'), 1600);
                        }, 50);
                    },
                 }"
                 x-on:whatsapp-chat-opened.window="toBottom()"
                 x-on:whatsapp-chat-received.window="received()"
                 x-on:whatsapp-scroll-to.window="scrollTo($event.detail.messageId)">
            <button type="button" x-show="! atBottom" x-cloak x-transition.opacity x-on:click="toBottom(true)"
                    aria-label="{{ __('Go to the latest messages') }}" x-tooltip="{{ __('Go to the latest messages') }}"
                    class="absolute right-4 bottom-4 z-20 grid size-11 place-items-center rounded-full bg-chat-bubble-in text-chat-meta shadow-lg ring-1 ring-separator hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                    data-chat-to-bottom>
                <x-icon name="chevron-down" class="size-6" aria-hidden="true" />
                <span x-show="unseen > 0" x-text="unseen > 99 ? '99+' : unseen"
                      class="absolute -top-1.5 -right-1 grid h-5 min-w-5 place-items-center rounded-full bg-chat-accent px-1 text-[0.6875rem] font-semibold text-on-tint"
                      data-chat-unseen></span>
            </button>
            <div x-ref="scroller" x-on:scroll.passive="onScroll()"
                 class="flex-1 overflow-y-auto px-3 py-4 md:px-12"
                 data-chat-messages>
                @if ($this->hasOlderMessages())
                    <div class="mb-4 flex justify-center">
                        <x-button :text="__('Load older messages')" flat sm color="secondary" data-chat-load-older
                                  x-on:click="keep = $el.closest('[data-chat-messages]').scrollHeight - $el.closest('[data-chat-messages]').scrollTop;
                                              const box = $el.closest('[data-chat-messages]');
                                              $wire.loadOlder().then(() => $nextTick(() => box.scrollTop = box.scrollHeight - keep))" />
                    </div>
                @endif

                @php
                    $senderPictures = $this->senderPictures;
                    $senderNicknames = $this->senderNicknames;
                @endphp
                @if (in_array(null, $senderPictures, true))
                    <div class="hidden" wire:key="sender-pictures-{{ md5(implode(',', array_keys(array_filter($senderPictures, is_null(...))))) }}"
                         x-init="$wire.loadSenderPictures()"></div>
                @endif

                <ol role="list" class="flex flex-col gap-1">
                    @php
                        $previousDay = null;
                        $previousSender = null;
                    @endphp
                    @foreach ($shownMessages as $message)
                        @php
                            $sentAt = $message->sent_at->setTimezone($timezone);
                            $sender = $message->from_me ? null : ($message->sender_jid ?? $message->sender_name);
                            $startsRun = $sentAt->toDateString() !== $previousDay || $sender !== $previousSender;
                            $showsSender = $chat->isGroup() && ! $message->from_me;
                            $previousSender = $message->from_me ? '' : $sender;
                        @endphp

                        @if ($sentAt->toDateString() !== $previousDay)
                            @php $previousDay = $sentAt->toDateString(); @endphp
                            <li class="my-3 flex justify-center" wire:key="day-{{ $previousDay }}">
                                <span class="rounded-control bg-chat-bubble-in px-3 py-1 text-xs font-medium text-chat-meta uppercase shadow-soft">
                                    {{ $sentAt->isToday() ? __('Today') : ($sentAt->isYesterday() ? __('Yesterday') : $sentAt->isoFormat('LL')) }}
                                </span>
                            </li>
                        @endif

                        <li wire:key="message-{{ $message->friendly_id }}" @class(['group flex items-center gap-1', 'flex-row-reverse' => $message->from_me, 'mb-4' => $message->reactions->isNotEmpty()])
                            data-chat-message="{{ $message->friendly_id }}" data-from-me="{{ $message->from_me ? 'true' : 'false' }}">
                            @if ($showsSender)
                                <div class="w-8 shrink-0 self-start">
                                    @if ($startsRun)
                                        <x-whatsapp.avatar size="sm" :name="$senderNicknames[$message->sender_jid ?? ''] ?? $message->senderLabel() ?? '?'"
                                                           :picture="$senderPictures[$message->sender_jid ?? ''] ?? null"
                                                           data-chat-sender-avatar="{{ $message->sender_jid }}" />
                                    @endif
                                </div>
                            @endif
                            @php
                                // An image fills the bubble, as on WhatsApp: a fixed width, a thin frame, the time over the image without a caption.
                                $imageBubble = $message->mediaKind() === 'image'
                                    && ($message->media_path !== null || ($message->canDownloadMedia() && $message->thumbnailUri() !== null));
                            @endphp
                            <div @class([
                                'relative max-w-[85%] rounded-[0.5rem] text-[0.9375rem] leading-snug text-chat-text shadow-soft md:max-w-[65%]',
                                'rounded-tr-none' => $message->from_me && $startsRun,
                                'rounded-tl-none' => ! $message->from_me && $startsRun,
                                'px-2 pt-1.5 pb-1' => ! $imageBubble,
                                'w-[330px] p-1' => $imageBubble,
                                'ring-2 ring-tint ring-offset-2 ring-offset-grouped-background' => in_array($message->friendly_id, $selected, true),
                                'bg-chat-bubble-out' => $message->from_me,
                                'bg-chat-bubble-in' => ! $message->from_me,
                                'opacity-75 outline-2 outline-dashed outline-offset-2 outline-danger/50' => $message->isRevoked(),
                            ])>
                                <span x-cloak x-show="$store.chatMenu.pending['{{ $message->friendly_id }}']" x-text="$store.chatMenu.pending['{{ $message->friendly_id }}']"
                                      @class(['absolute -bottom-4 z-10 animate-pulse rounded-full bg-chat-bubble-in px-1.5 py-0.5 text-sm shadow-soft ring-1 ring-chat-wallpaper', 'right-2' => $message->from_me, 'left-2' => ! $message->from_me])
                                      aria-hidden="true" data-chat-reaction-pending></span>
                                @if ($startsRun && $message->senderLabel() !== null)
                                    <p @class(['text-[0.8125rem] font-medium text-chat-link', 'px-2 pt-0.5 pb-1' => $imageBubble]) data-chat-sender>{{ $senderNicknames[$message->sender_jid ?? ''] ?? $message->senderLabel() }}</p>
                                @endif
                                @if ($message->isRevoked())
                                    <p class="mb-0.5 flex items-center gap-1 text-xs font-semibold text-danger italic"
                                       x-tooltip="{{ __('Deleted for everyone on WhatsApp :when. Jarvis kept what it said.', ['when' => $message->revoked_at->setTimezone($timezone)->isoFormat('lll')]) }}"
                                       data-chat-revoked>
                                        <x-icon name="no-symbol" class="size-4" aria-hidden="true" />
                                        {{ __('Deleted on WhatsApp') }}
                                    </p>
                                @endif
                                <x-whatsapp.message-context :message="$message" :image-bubble="$imageBubble" />
                                <x-whatsapp.message-payload :message="$message" />
                                @if ($message->mediaKind() !== null)
                                    @php
                                        $mediaUrl = route('whatsapp.chat.media', $message);
                                        $mediaLoaded = $message->media_path !== null;
                                        $mediaReachable = $mediaLoaded || $message->canDownloadMedia();
                                        $showsInPlace = $mediaReachable && (in_array($message->mediaKind(), ['audio', 'video'], true)
                                            || ($message->mediaKind() === 'image' && ($mediaLoaded || $message->thumbnailUri() !== null)));
                                    @endphp

                                    @if ($mediaLoaded && $message->mediaKind() === 'image')
                                        <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="block" data-chat-media-inline="image">
                                            <img src="{{ $mediaUrl }}" alt="{{ $message->media_name ?? __('Image') }}" loading="lazy"
                                                 class="block max-h-[338px] min-h-32 w-full rounded-control bg-fill object-cover" />
                                        </a>
                                    @elseif ($showsInPlace && $message->mediaKind() === 'image')
                                        <button type="button" wire:click="loadMedia('{{ $message->friendly_id }}')" wire:loading.attr="disabled"
                                                wire:target="loadMedia('{{ $message->friendly_id }}')"
                                                class="relative block w-full overflow-hidden rounded-control focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                                data-chat-media-inline="image" data-chat-load="{{ $message->friendly_id }}">
                                            <img src="{{ $message->thumbnailUri() }}" alt="" class="block max-h-[338px] min-h-32 w-full scale-110 object-cover blur-md" />
                                            <span class="absolute inset-0 m-auto inline-flex h-10 w-fit items-center gap-2 rounded-full bg-media-scrim px-4 text-sm font-semibold text-on-tint">
                                                <x-icon name="arrow-path" class="size-5 animate-spin" wire:loading wire:target="loadMedia('{{ $message->friendly_id }}')" aria-hidden="true" />
                                                <x-icon name="arrow-down-circle" class="size-5" wire:loading.remove wire:target="loadMedia('{{ $message->friendly_id }}')" aria-hidden="true" />
                                                {{ __('Load') }}
                                                @if ($message->media_size !== null)
                                                    <span class="font-normal opacity-80">{{ \Illuminate\Support\Number::fileSize($message->media_size) }}</span>
                                                @endif
                                            </span>
                                        </button>
                                    @elseif ($showsInPlace && $message->mediaKind() === 'video')
                                        <x-whatsapp.video-player :message="$message" data-chat-media-inline="video" />
                                    @elseif ($showsInPlace && $message->mediaKind() === 'audio')
                                        <x-whatsapp.audio-player :message="$message" data-chat-media-inline="audio" />
                                    @endif

                                    @if (! $showsInPlace && (! $mediaLoaded || $message->mediaKind() === 'document'))
                                        <div class="my-1 flex items-center gap-3 rounded-control bg-fill px-3 py-2" data-chat-media="{{ $message->mediaKind() }}">
                                            <x-icon :name="match ($message->mediaKind()) { 'image' => 'photo', 'video' => 'film', 'audio' => 'microphone', default => 'document' }"
                                                    class="size-7 shrink-0" aria-hidden="true" />
                                            <span class="min-w-0 flex-1">
                                                @if ($mediaLoaded)
                                                    <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="block truncate text-sm font-medium underline-offset-2 hover:underline" data-chat-media-inline="document">
                                                        {{ $message->media_name ?? __('Document') }}
                                                    </a>
                                                @else
                                                    <span class="block truncate text-sm font-medium">
                                                        {{ $message->media_name ?? match ($message->mediaKind()) { 'image' => __('Image'), 'video' => __('Video'), 'audio' => __('Audio'), default => __('Document') } }}
                                                    </span>
                                                @endif
                                                @if ($message->media_size !== null)
                                                    <span class="block text-xs opacity-75">{{ \Illuminate\Support\Number::fileSize($message->media_size) }}</span>
                                                @endif
                                            </span>

                                            @if (! $mediaLoaded && $message->canDownloadMedia())
                                                <button type="button" wire:click="loadMedia('{{ $message->friendly_id }}')"
                                                        wire:loading.attr="disabled" wire:target="loadMedia('{{ $message->friendly_id }}')"
                                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-semibold hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-60"
                                                        data-chat-load="{{ $message->friendly_id }}">
                                                    <x-icon name="arrow-path" class="size-5 animate-spin" wire:loading wire:target="loadMedia('{{ $message->friendly_id }}')" aria-hidden="true" />
                                                    <x-icon name="eye" class="size-5" wire:loading.remove wire:target="loadMedia('{{ $message->friendly_id }}')" aria-hidden="true" />
                                                    {{ __('Load') }}
                                                </button>
                                            @elseif (! $mediaLoaded)
                                                <span class="shrink-0 text-xs italic opacity-80">{{ __('Too big. See it on the phone.') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                @endif

                                @if (($message->mediaKind() === null && $message->payload === null) || ($message->body !== null && $message->payload === null))
                                    @if ($message->body !== null)
                                        <p @class(['break-words whitespace-pre-wrap', 'px-2 pt-1' => $imageBubble]) data-chat-body>{{ $message->bodyHtml() }}</p>
                                    @else
                                        @php $bubbleSummary = $message->summary(); @endphp
                                        <p class="flex items-start gap-2 text-[0.875rem] italic opacity-80" data-chat-body data-chat-unsupported="{{ $message->message_type }}">
                                            <x-icon :name="$bubbleSummary['icon'] ?? 'chat-bubble-left'" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                            <span>{{ $bubbleSummary['label'] }}</span>
                                        </p>
                                    @endif
                                @endif
                                <p @class([
                                    'flex items-center justify-end gap-1 text-[0.6875rem]',
                                    'mt-0.5' => ! $imageBubble || $message->body !== null,
                                    'px-2 pb-0.5' => $imageBubble && $message->body !== null,
                                    'relative z-10 -mt-7 mr-1.5 mb-1.5 ml-auto w-fit rounded-full bg-media-scrim px-1.5 py-0.5 text-on-tint' => $imageBubble && $message->body === null,
                                    'text-chat-meta' => ! ($imageBubble && $message->body === null),
                                ]) data-chat-time>
                                    @if ($message->from_me && isset($drafted[$message->external_id]))
                                        <button type="button" class="pointer-events-auto -my-1 grid size-5 place-items-center rounded-full hover:bg-fill hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                                x-on:click.stop="$dispatch('whatsapp-draft', { conversationId: @js($drafted[$message->external_id]), sentMessageId: @js($message->friendly_id) })"
                                                x-tooltip="{{ __('Written with AI: open its conversation') }}" aria-label="{{ __('Written with AI: open its conversation') }}"
                                                data-chat-drafted="{{ $drafted[$message->external_id] }}">
                                            <x-icon name="sparkles" class="size-3.5" aria-hidden="true" />
                                        </button>
                                    @endif
                                    @if ($message->starred_at !== null)
                                        <x-icon name="star" solid class="size-3" :aria-label="__('Starred')" data-chat-starred />
                                    @endif
                                    @if ($message->isPinned())
                                        <x-icon name="paper-clip" class="size-3" :aria-label="__('Pinned')" data-chat-message-pinned />
                                    @endif
                                    @if ($message->edited_at !== null)
                                        <span class="italic" data-chat-edited>{{ __('Edited') }}</span>
                                    @endif
                                    {{ $sentAt->format('H:i') }}
                                    @if ($message->from_me)
                                        <x-whatsapp.receipt :receipt="$message->receipt" />
                                    @endif
                                </p>

                                @if ($message->reactions->isNotEmpty())
                                    @php
                                        $reactorName = fn (\App\Models\WhatsappChatReaction $reaction): string => $reaction->isMine()
                                            ? __('You')
                                            : ($senderNicknames[$reaction->reactor] ?? ($chat->isGroup() ? (str_ends_with($reaction->reactor, '@s.whatsapp.net') ? '+'.\Illuminate\Support\Str::before($reaction->reactor, '@') : __('Someone in the group')) : $chat->displayName()));
                                    @endphp
                                    <div @class(['relative z-10 -mb-5 flex', 'justify-end' => $message->from_me, 'px-2' => $imageBubble]) data-chat-reactions="{{ $message->friendly_id }}">
                                        <div class="flex items-center gap-1 rounded-full bg-chat-bubble-in px-1.5 py-0.5 text-sm text-chat-text shadow-soft ring-1 ring-chat-wallpaper">
                                            @foreach ($message->reactionSummary() as $reaction)
                                                <button type="button" wire:click="react('{{ $message->friendly_id }}', @js($reaction['emoji']))"
                                                        x-tooltip="{{ $message->reactions->where('emoji', $reaction['emoji'])->map($reactorName)->join(', ') }}"
                                                        @class(['flex items-center gap-0.5 rounded-full px-0.5', 'bg-tint/15' => $reaction['mine']])
                                                        data-chat-reaction="{{ $reaction['emoji'] }}" @if ($reaction['mine']) data-chat-reaction-mine @endif>
                                                    <span>{{ $reaction['emoji'] }}</span>
                                                    @if ($reaction['count'] > 1)
                                                        <span class="text-xs text-secondary-label">{{ $reaction['count'] }}</span>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>

                            @php
                                $connected = (bool) $this->account?->isOpen();
                                $picked = in_array($message->friendly_id, $selected, true);
                            @endphp
                            <div @class([
                                'flex shrink-0 gap-1 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 pointer-coarse:opacity-100 has-[[aria-expanded=true]]:opacity-100',
                                'opacity-100' => $picked,
                                'opacity-0' => ! $picked,
                            ])>
                                @if ($connected && ! $message->isRevoked())
                                    <button type="button" x-tooltip="{{ __('React') }}" aria-label="{{ __('React') }}" aria-haspopup="menu"
                                            x-on:click.stop="$store.chatMenu.show('react', $el)" x-bind:aria-expanded="($store.chatMenu.anchor === $el).toString()"
                                            data-id="{{ $message->friendly_id }}" data-align="{{ $message->from_me ? 'end' : 'start' }}"
                                            class="grid size-8 place-items-center rounded-full bg-chat-bubble-in text-chat-meta shadow-soft hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                            data-chat-react-open="{{ $message->friendly_id }}">
                                        <x-icon name="face-smile" class="size-5" aria-hidden="true" />
                                    </button>
                                @endif
                                <button type="button" x-tooltip="{{ __('Message options') }}" aria-label="{{ __('Message options') }}" aria-haspopup="menu"
                                        x-on:click.stop="$store.chatMenu.show('message', $el)" x-bind:aria-expanded="($store.chatMenu.anchor === $el).toString()"
                                        data-id="{{ $message->friendly_id }}" data-align="{{ $message->from_me ? 'end' : 'start' }}"
                                        data-reply="{{ $connected && ! $message->isRevoked() ? 1 : 0 }}"
                                        data-forward="{{ $connected && ! $message->isRevoked() && ($message->mediaKind() !== null || filled($message->body)) ? 1 : 0 }}"
                                        data-starred="{{ $message->starred_at !== null ? 1 : 0 }}"
                                        data-picked="{{ $picked ? 1 : 0 }}"
                                        data-info="{{ $message->from_me ? 1 : 0 }}"
                                        data-edit="{{ $connected && $message->canEdit() ? 1 : 0 }}"
                                        data-delete="{{ $connected && $message->canDeleteForEveryone() ? 1 : 0 }}"
                                        @if ($message->mediaKind() !== null && $message->media_path !== null)
                                            data-download="{{ route('whatsapp.chat.media', $message).'?download=1' }}" data-chat-download="{{ $message->friendly_id }}"
                                        @endif
                                        @if ($connected && $message->canEdit()) data-chat-edit="{{ $message->friendly_id }}" @endif
                                        @if ($connected && $message->canDeleteForEveryone()) data-chat-delete-everyone="{{ $message->friendly_id }}" @endif
                                        class="grid size-8 place-items-center rounded-full bg-chat-bubble-in text-chat-meta shadow-soft hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                        data-chat-message-menu-open="{{ $message->friendly_id }}">
                                    <x-icon name="chevron-down" class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                        </li>
                    @endforeach

                    @foreach ($this->outgoing as $delivery)
                        <li wire:key="outgoing-{{ $delivery->id }}" class="flex justify-end" data-chat-outgoing="{{ $delivery->status->slug }}">
                            <div class="max-w-[85%] rounded-[0.5rem] bg-chat-bubble-out/70 px-2 pt-1.5 pb-1 text-[0.9375rem] text-chat-text shadow-soft md:max-w-[65%]">
                                @if ($delivery->media_path !== null)
                                    <p class="flex items-center gap-1 text-sm font-medium"><x-icon name="paper-clip" class="size-4" aria-hidden="true" />{{ ($delivery->payload['sticker'] ?? false) ? __('Sticker') : ($delivery->media_name ?? __('File')) }}</p>
                                @endif
                                @if (filled($delivery->body))
                                    <p class="break-words whitespace-pre-wrap">{{ $delivery->body }}</p>
                                @endif
                                <p class="mt-0.5 text-right text-[0.6875rem] text-chat-meta">
                                    @if ($delivery->status->slug === \App\Models\DeliveryStatus::FAILED)
                                        <span class="font-semibold">{{ __('Not sent') }}</span> · {{ $delivery->error_message }}
                                    @else
                                        {{ __('Sending…') }}
                                    @endif
                                </p>
                            </div>
                        </li>
                    @endforeach
                </ol>

                @if ($this->outgoing->contains(fn ($delivery) => $delivery->status->slug === \App\Models\DeliveryStatus::PENDING))
                    <div wire:poll.5s class="hidden"></div>
                @endif
            </div>
            </div>

            @if ($selected !== [])
                <div class="flex items-center gap-3 border-t border-separator bg-secondary-grouped-background px-4 py-2 text-sm" data-chat-selection>
                    <x-icon name="sparkles" solid class="size-5 shrink-0 text-tint" aria-hidden="true" />
                    <span class="flex-1 text-label">{{ trans_choice('{1} 1 message selected|[2,*] :count messages selected', count($selected), ['count' => count($selected)]) }}</span>
                    <button type="button" wire:click="clearSelected" class="text-secondary-label hover:text-label hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" data-chat-clear-selection>{{ __('Clear') }}</button>
                    <x-button :text="__('Forward')" icon="arrow-uturn-right" sm flat color="secondary" wire:click="startForward" data-chat-forward-selected />
                    <x-button :text="__('Answer with AI')" icon="sparkles" sm x-on:click="$dispatch('whatsapp-draft', { replyTo: $wire.selected })" data-chat-draft-selected />
                </div>
            @endif

            @if ($chat->isBlocked())
                <p class="border-t border-separator bg-secondary-grouped-background px-4 py-2 text-center text-xs text-secondary-label" data-chat-blocked>
                    {{ __('You blocked this contact. Unblock them in the contact info to message them.') }}
                </p>
            @endif

            @php
                $connected = (bool) $this->account?->isOpen();
                $kindsMenu = [
                    \App\Livewire\Whatsapp\ChatContent::KIND_STICKER => ['face-smile', __('Sticker')],
                    \App\Livewire\Whatsapp\ChatContent::KIND_POLL => ['chart-bar', __('Poll')],
                    \App\Livewire\Whatsapp\ChatContent::KIND_LOCATION => ['map-pin', __('Location')],
                    \App\Livewire\Whatsapp\ChatContent::KIND_CONTACTS => ['user-circle', __('Contact')],
                ];
            @endphp
            {{--
                The composer, as on WhatsApp Web: "+" opens what can be sent
                besides a text (files, sticker, poll, location, contact) and the
                scheduling and the AI; the field formats a selection and mentions
                the members of a group; the microphone becomes the send button
                as soon as there is something to send.
            --}}
            <form wire:submit="send" x-on:submit="sync()"
                  x-data="whatsappEditor({ model: 'text', group: @js($chat->isGroup()), send: 'send', typing: @js((bool) $chat?->sendsTyping()) })"
                  class="flex items-end gap-1.5 bg-chat-panel px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] md:gap-2 md:px-4 md:py-2.5" data-chat-composer>
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="menu"
                            aria-label="{{ __('Attach') }}" x-tooltip="{{ __('Attach') }}" @disabled(! $connected)
                            class="grid size-10 shrink-0 place-items-center rounded-full text-chat-meta transition-colors hover:bg-fill hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:cursor-not-allowed disabled:opacity-40"
                            x-bind:class="open ? 'bg-fill text-chat-text' : ''" data-chat-attach-menu>
                        <x-icon name="plus" class="size-6 transition-transform duration-200 motion-reduce:transition-none" x-bind:class="open ? 'rotate-45' : ''" aria-hidden="true" />
                    </button>
                    <input id="chat-file" type="file" wire:model="attachment" class="sr-only" x-on:change="open = false" @disabled(! $connected) data-chat-attach>
                    <input id="chat-media" type="file" accept="image/*,video/*" wire:model="attachment" class="sr-only" x-on:change="open = false" @disabled(! $connected) data-chat-attach-media>
                    <div x-show="open" x-cloak x-transition.origin.bottom.left role="menu"
                         class="absolute bottom-14 left-0 z-30 flex w-64 flex-col rounded-[1.25rem] bg-chat-bubble-in p-2 text-chat-text shadow-lg ring-1 ring-separator">
                        <label for="chat-file" role="menuitem" class="flex cursor-pointer items-center gap-3 rounded-control px-3 py-2.5 text-[0.9375rem] hover:bg-fill">
                            <x-icon name="document" class="size-5 text-chat-accent" aria-hidden="true" />{{ __('Document') }}
                        </label>
                        <label for="chat-media" role="menuitem" class="flex cursor-pointer items-center gap-3 rounded-control px-3 py-2.5 text-[0.9375rem] hover:bg-fill">
                            <x-icon name="photo" class="size-5 text-chat-accent" aria-hidden="true" />{{ __('Photos and videos') }}
                        </label>
                        @foreach ($kindsMenu as $kind => [$kindIcon, $kindLabel])
                            <button type="button" role="menuitem" class="flex items-center gap-3 rounded-control px-3 py-2.5 text-left text-[0.9375rem] hover:bg-fill"
                                    x-on:click="open = false; $dispatch('whatsapp-compose', { kind: '{{ $kind }}', replyToId: @js($replyToId) })" data-chat-compose="{{ $kind }}">
                                <x-icon :name="$kindIcon" class="size-5 text-chat-accent" aria-hidden="true" />{{ $kindLabel }}
                            </button>
                        @endforeach
                        <div class="my-1 border-t border-separator"></div>
                        <button type="button" role="menuitem" class="flex items-center gap-3 rounded-control px-3 py-2.5 text-left text-[0.9375rem] hover:bg-fill"
                                x-on:click="open = false; sync(); $wire.startSchedule()" data-chat-menu-schedule>
                            <x-icon name="clock" class="size-5 text-chat-accent" aria-hidden="true" />{{ __('Schedule message') }}
                        </button>
                        <button type="button" role="menuitem" class="flex items-center gap-3 rounded-control px-3 py-2.5 text-left text-[0.9375rem] hover:bg-fill"
                                x-on:click="open = false; $dispatch('whatsapp-draft', { replyTo: $wire.selected })" data-chat-menu-draft>
                            <x-icon name="sparkles" class="size-5 text-chat-accent" aria-hidden="true" />{{ __('Write with AI') }}
                        </button>
                    </div>
                </div>

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div wire:loading.flex wire:target="attachment" class="items-center gap-2 text-xs text-secondary-label">{{ __('Uploading…') }}</div>

                    @if ($attachment !== null)
                        <div class="flex items-center gap-2 rounded-control bg-fill px-3 py-1.5 text-sm text-label" data-chat-attachment>
                            <x-icon name="paper-clip" class="size-4 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{{ $attachment->getClientOriginalName() }}</span>
                            <span class="shrink-0 text-xs text-secondary-label">{{ \Illuminate\Support\Number::fileSize($attachment->getSize()) }}</span>
                            <button type="button" wire:click="removeAttachment" class="shrink-0 text-secondary-label hover:text-label" x-tooltip="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                <x-icon name="x-mark" class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    @endif

                    @error('attachment')
                        <p role="alert" class="text-xs text-danger">{{ $message }}</p>
                    @enderror

                    @if ($this->replyTo !== null)
                        @php $replyTo = $this->replyTo; @endphp
                        <div class="flex items-center gap-2 rounded-control border-l-4 border-tint bg-fill px-3 py-1.5" data-chat-reply-bar="{{ $replyTo->friendly_id }}">
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs font-semibold text-tint">{{ $replyTo->from_me ? __('You') : ($replyTo->senderLabel() ?? $chat->displayName()) }}</span>
                                <span class="block truncate text-sm text-secondary-label">{{ $replyTo->body ?? match ($replyTo->mediaKind()) { 'image' => __('Image'), 'video' => __('Video'), 'audio' => __('Audio'), 'document' => __('Document'), default => __('Message') } }}</span>
                            </span>
                            <button type="button" wire:click="cancelReply" class="shrink-0 text-secondary-label hover:text-label" x-tooltip="{{ __('Cancel the reply') }}" aria-label="{{ __('Cancel the reply') }}" data-chat-reply-cancel>
                                <x-icon name="x-mark" class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    @endif

                    <div class="relative flex items-end gap-1 rounded-[1.5rem] bg-chat-input px-4 py-2 focus-within:ring-2 focus-within:ring-tint/40">
                        <x-whatsapp.mention-list />
                        <div x-ref="field" wire:ignore id="chat-text" role="textbox" aria-multiline="true" aria-label="{{ __('Message') }}"
                             contenteditable="{{ $connected ? 'true' : 'false' }}"
                             data-placeholder="{{ $connected ? ($attachment !== null ? __('Add a caption') : __('Type a message')) : __('This number is not connected') }}"
                             x-bind:data-empty="empty.toString()"
                             x-on:input="onInput()" x-on:keydown="onKeydown($event)" x-on:paste="onPaste($event)" x-on:keyup="track()" x-on:click="track()"
                             x-on:whatsapp-chat-drafted.window="$nextTick(() => caretToEnd())"
                             class="chat-field max-h-40 min-h-6 min-w-0 flex-1 overflow-y-auto text-[0.9375rem] leading-6 break-words whitespace-pre-wrap text-chat-text outline-none"
                             data-chat-text></div>
                        {{-- On a phone the keyboard has the emojis. --}}
                        <div class="relative hidden shrink-0 md:block" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" aria-label="{{ __('Emoji') }}" x-tooltip="{{ __('Emoji') }}" @disabled(! $connected)
                                    class="grid size-6 place-items-center text-chat-meta hover:text-chat-text focus:outline-none disabled:opacity-40" data-chat-emoji>
                                <x-icon name="face-smile" class="size-6" aria-hidden="true" />
                            </button>
                            <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 bottom-10 z-30 rounded-card bg-secondary-grouped-background p-2 shadow-lg ring-1 ring-separator"
                                 x-on:emoji-picked="insert($event.detail.emoji)">
                                <x-whatsapp.emoji-grid />
                            </div>
                        </div>
                    </div>
                    @error('text')
                        <p role="alert" class="text-xs text-danger" data-chat-error>{{ $message }}</p>
                    @enderror
                </div>

                <x-whatsapp.format-balloon />

                <div class="flex shrink-0 items-center gap-1" x-data="voiceRecorder('voiceNote', 'sendVoice')" data-chat-voice>
                    <template x-if="recording">
                        <div class="flex items-center gap-1">
                            <x-whatsapp.chat-action icon="trash" :title="__('Discard')" x-on:click="cancel()" data-chat-voice-cancel />
                            <span class="flex items-center gap-1.5 px-1 text-sm tabular-nums text-danger" aria-live="polite">
                                <span class="size-2 animate-pulse rounded-full bg-danger"></span>
                                <span x-text="elapsed"></span>
                            </span>
                            <button type="button" x-on:click="stop()" aria-label="{{ __('Send the voice note') }}" x-tooltip="{{ __('Send the voice note') }}"
                                    class="grid size-11 place-items-center rounded-full bg-chat-accent text-on-tint hover:opacity-90" data-chat-voice-send>
                                <x-icon name="paper-airplane" class="size-5" aria-hidden="true" />
                            </button>
                        </div>
                    </template>
                    <button type="button" x-show="! recording && empty && {{ $attachment === null ? 'true' : 'false' }}" x-on:click="start()" @disabled(! $connected)
                            aria-label="{{ __('Record a voice note') }}" x-tooltip="{{ __('Record a voice note') }}"
                            class="grid size-11 place-items-center rounded-full text-chat-meta transition-colors hover:bg-fill hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:cursor-not-allowed disabled:opacity-40"
                            data-chat-voice-record>
                        <x-icon name="microphone" class="size-6" aria-hidden="true" />
                    </button>
                    <button type="submit" x-show="! recording && ! (empty && {{ $attachment === null ? 'true' : 'false' }})" x-cloak
                            aria-label="{{ __('Send') }}" x-tooltip="{{ __('Send') }}" @disabled(! $connected) wire:loading.attr="disabled" wire:target="send"
                            class="grid size-11 place-items-center rounded-full bg-chat-accent text-on-tint transition-opacity hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            data-chat-send>
                        <x-icon name="paper-airplane" class="size-5" wire:loading.remove wire:target="send" aria-hidden="true" />
                        <x-icon name="arrow-path" class="size-5 animate-spin" wire:loading wire:target="send" aria-hidden="true" />
                    </button>
                    <p x-show="failed" x-cloak role="alert" class="max-w-32 text-xs text-danger">{{ __('The browser did not let Jarvis use the microphone.') }}</p>
                </div>
            </form>
        @endif
    </main>

    <x-whatsapp.chat-menus :reminders="auth()->user()->can('access-module', \App\Models\Module::REMINDERS)" />

    @if ($chat !== null)
        <x-slide wire="showInfo" size="md" :title="$chat->displayName()">
            <livewire:whatsapp.chat-info :chat-id="$chat->friendly_id" :tab="$infoTab" :key="'chat-info-'.$chat->friendly_id" />
        </x-slide>

        <livewire:whatsapp.chat-content :chat-id="$chat->friendly_id" :key="'chat-content-'.$chat->friendly_id" />

        <livewire:whatsapp.draft-assistant :chat-id="$chat->friendly_id" :key="'draft-assistant-'.$chat->friendly_id" />
    @endif

    @if ($this->account !== null)
        <livewire:whatsapp.new-group :account-id="$this->account->friendly_id" :key="'new-group-'.$this->account->friendly_id" />
    @endif

    <x-modal wire="showEdit" :title="__('Edit message')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5" novalidate data-chat-edit-form>
            <x-textarea :label="__('Message')" wire:model="editText" rows="4" maxlength="{{ \App\Actions\Whatsapp\SendWhatsappChatMessage::MAX_LENGTH }}"
                        :hint="__('WhatsApp lets you edit a message for :minutes minutes after sending it.', ['minutes' => \App\Models\WhatsappChatMessage::EDIT_MINUTES])" data-chat-edit-text />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveEdit" data-chat-edit-save />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showDeleteForEveryone" :title="__('Delete for everyone?')">
        <div class="flex flex-col gap-5">
            <p class="text-sm text-label">{{ __('The message goes from everyone\'s WhatsApp. Jarvis keeps it, marked as deleted.') }}</p>
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button :text="__('Delete for everyone')" color="red" icon="trash" wire:click="deleteForEveryone" loading="deleteForEveryone" data-chat-delete-everyone-confirm />
            </div>
        </div>
    </x-modal>

    <x-modal wire="showMessageInfo" :title="__('Message info')">
        @php $infoMessage = $showMessageInfo ? $this->infoMessage : null; @endphp
        @if ($infoMessage !== null)
            <div class="flex flex-col gap-4" data-chat-message-info-modal="{{ $infoMessage->friendly_id }}">
                <div class="rounded-card bg-fill px-3 py-2 text-sm text-label">
                    <p class="line-clamp-4 break-words whitespace-pre-wrap">{{ $infoMessage->body ?? $infoMessage->bodyOrPlaceholder() }}</p>
                    <p class="mt-1 text-right text-xs text-secondary-label">{{ __('Sent :when', ['when' => $infoMessage->sent_at->setTimezone($timezone)->isoFormat('lll')]) }}</p>
                </div>

                @php
                    $receiptRow = $infoMessage->receipts->first();
                    $receiptSteps = [
                        [__('Read'), $receiptRow?->read_at, $infoMessage->receipt >= \App\Models\WhatsappChatMessage::RECEIPT_READ],
                        [__('Delivered'), $receiptRow?->delivered_at, $infoMessage->receipt >= \App\Models\WhatsappChatMessage::RECEIPT_DELIVERED],
                    ];
                    if (in_array($infoMessage->mediaKind(), ['audio', 'video'], true)) {
                        array_unshift($receiptSteps, [__('Played'), $receiptRow?->played_at, $infoMessage->receipt >= \App\Models\WhatsappChatMessage::RECEIPT_PLAYED]);
                    }
                @endphp
                <dl class="flex flex-col divide-y divide-separator">
                    @foreach ($receiptSteps as [$stepLabel, $stepAt, $stepReached])
                        <div class="flex items-center justify-between gap-3 py-2 text-sm" data-chat-message-info-step>
                            <dt class="flex items-center gap-2 text-label">
                                <x-icon :name="$stepReached ? 'check-circle' : 'clock'" :solid="$stepReached" @class(['size-5', 'text-tint' => $stepReached, 'text-secondary-label' => ! $stepReached]) aria-hidden="true" />
                                {{ $stepLabel }}
                            </dt>
                            <dd class="text-secondary-label">
                                {{ $stepAt !== null ? $stepAt->setTimezone($timezone)->isoFormat('lll') : ($stepReached ? __('Yes, time unknown') : '—') }}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if ($chat?->isGroup())
                    <p class="text-xs text-secondary-label">{{ __('In a group, WhatsApp does not tell Jarvis who of the members got or read it: the ticks show how far it got.') }}</p>
                @endif
            </div>
        @endif
    </x-modal>

    <x-modal wire="showNickname" :title="__('Nickname')">
        <form wire:submit="saveNickname" class="flex flex-col gap-5" novalidate data-chat-nickname-form>
            <x-input :label="__('Nickname')" wire:model="nickname" maxlength="{{ \App\Actions\Whatsapp\SetWhatsappChatNickname::MAX_LENGTH }}"
                     :hint="$chat !== null ? __('Only you see it, in place of “:name”. Leave it blank to show that name again.', ['name' => $chat->name ?? $chat->phoneNumber() ?? $chat->jid]) : null" />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveNickname" />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showTags" :title="__('Tags')">
        <div class="flex flex-col gap-5" data-chat-tags-modal>
            @if ($this->tags->isNotEmpty())
                <fieldset class="flex flex-wrap gap-2">
                    <legend class="mb-2 text-sm font-medium text-label">{{ __('Tags of this chat') }}</legend>
                    @foreach ($this->tags as $tag)
                        <label class="cursor-pointer" wire:key="pick-tag-{{ $tag->friendly_id }}">
                            <input type="checkbox" value="{{ $tag->friendly_id }}" wire:model="chatTags" class="peer sr-only" data-chat-pick-tag="{{ $tag->friendly_id }}">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-fill px-3 py-1.5 text-sm text-label ring-offset-2 ring-offset-secondary-grouped-background peer-checked:bg-tint-fill peer-checked:text-on-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint">
                                <span class="size-2.5 rounded-full" style="background-color: {{ $tag->hex() }}"></span>{{ $tag->name }}
                            </span>
                        </label>
                    @endforeach
                </fieldset>
            @else
                <p class="text-sm text-secondary-label">{{ __('No tags yet. Create the first one below.') }}</p>
            @endif

            <form wire:submit="saveTag" class="flex flex-col gap-3" novalidate data-chat-tag-form>
                <div class="flex items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <x-input :label="$editingTagId !== null ? __('Edit tag') : __('New tag')" id="chat-tag-name" wire:model="tagName" maxlength="{{ \App\Models\WhatsappTag::MAX_NAME_LENGTH }}"
                                 :placeholder="__('Clients, Family…')" data-chat-tag-name />
                    </div>
                    <x-button type="submit" :text="$editingTagId !== null ? __('Save') : __('Add')" icon="check" loading="saveTag" />
                </div>
                @error('tagName') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror
                <fieldset class="flex flex-wrap gap-2">
                    <legend class="sr-only">{{ __('Color') }}</legend>
                    @foreach (\App\Models\WhatsappTarget::PRIORITY_COLORS as $colorName => $hex)
                        <label class="cursor-pointer" x-tooltip="{{ __(ucfirst($colorName)) }}">
                            <input type="radio" name="tag-color" value="{{ $colorName }}" wire:model="tagColor" class="peer sr-only">
                            <span class="block size-6 rounded-full ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint"
                                  style="background-color: {{ $hex }}"></span>
                            <span class="sr-only">{{ __(ucfirst($colorName)) }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </form>

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="button" :text="__('Save')" icon="check" wire:click="saveChatTags" loading="saveChatTags" data-chat-tags-save />
            </div>
        </div>
    </x-modal>

    <x-modal wire="showSettings" :title="__('Chat settings')">
        <div class="flex flex-col gap-6" data-chat-settings-form>
            @if ($this->account !== null)
                <form wire:submit="savePrivacy" class="flex flex-col gap-4" novalidate data-chat-privacy>
                    <div class="flex flex-col gap-1">
                        <h3 class="text-sm font-semibold text-label">{{ __('Privacy and notifications') }}</h3>
                        <p class="text-xs text-secondary-label">{{ __('Off, the chat is read and typed in Jarvis only, and the contact sees nothing.') }} {{ __('Notifications also need the Jarvis app signed in, with notifications allowed.') }}</p>
                    </div>

                    <div class="flex flex-col gap-3 rounded-control bg-fill p-3">
                        <p class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Number :nickname (every chat)', ['nickname' => $this->account->nickname]) }}</p>
                        <x-toggle wire:model="accountPrivacy.read_receipts" :label="__('Send read receipts (blue ticks) when I see a chat')" data-privacy-account-read />
                        <x-toggle wire:model="accountPrivacy.typing" :label="__('Show “typing…” while I type')" data-privacy-account-typing />
                        <x-toggle wire:model="accountPrivacy.notifications" :label="__('Notify new messages on my phone (Jarvis app)')" data-privacy-account-notifications />
                        <x-toggle wire:model="accountPrivacy.phone_reads" :label="__('Mark as read here what I read on the WhatsApp app')" data-privacy-account-phone-reads />
                    </div>

                    @if ($chat !== null)
                        @php $followLabel = fn (bool $on): string => __('As the number (:state)', ['state' => $on ? __('on') : __('off')]); @endphp
                        <div class="flex flex-col gap-3 rounded-control bg-fill p-3">
                            <p class="text-xs font-semibold tracking-wide text-secondary-label uppercase">{{ __('Only :name', ['name' => $chat->displayName()]) }}</p>
                            <div class="flex flex-col gap-1.5">
                                <p class="text-sm text-label">{{ __('Read receipts') }}</p>
                                <x-ui.segmented-control name="privacy-chat-read" :label="__('Read receipts')" wire:model="chatPrivacy.read_receipts" :value="$chatPrivacy['read_receipts']"
                                                        :options="[\App\Livewire\Whatsapp\Chat::FOLLOW_ACCOUNT => $followLabel((bool) $this->account->sends_read_receipts), 'on' => __('On'), 'off' => __('Off')]" data-privacy-chat-read />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <p class="text-sm text-label">{{ __('“Typing…”') }}</p>
                                <x-ui.segmented-control name="privacy-chat-typing" :label="__('“Typing…”')" wire:model="chatPrivacy.typing" :value="$chatPrivacy['typing']"
                                                        :options="[\App\Livewire\Whatsapp\Chat::FOLLOW_ACCOUNT => $followLabel((bool) $this->account->sends_typing), 'on' => __('On'), 'off' => __('Off')]" data-privacy-chat-typing />
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <p class="text-sm text-label">{{ __('Notifications on the phone') }}</p>
                                <x-ui.segmented-control name="privacy-chat-notifications" :label="__('Notifications on the phone')" wire:model="chatPrivacy.notifications" :value="$chatPrivacy['notifications']"
                                                        :options="[\App\Livewire\Whatsapp\Chat::FOLLOW_ACCOUNT => $followLabel((bool) $this->account->notifies_messages), 'on' => __('On'), 'off' => __('Off')]" data-privacy-chat-notifications />
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end">
                        <x-button type="submit" :text="__('Save')" icon="check" loading="savePrivacy" data-chat-privacy-save />
                    </div>
                </form>
            @endif

            <section class="flex flex-col gap-3" data-chat-settings-tags>
                <h3 class="text-sm font-semibold text-label">{{ __('Tags') }}</h3>
                @if ($this->tags->isNotEmpty())
                    <ul role="list" class="flex flex-col divide-y divide-separator rounded-control bg-fill">
                        @foreach ($this->tags as $tag)
                            <li class="flex items-center gap-3 px-3 py-2" wire:key="settings-tag-{{ $tag->friendly_id }}">
                                <span class="size-3 rounded-full" style="background-color: {{ $tag->hex() }}"></span>
                                <span class="min-w-0 flex-1 truncate text-sm text-label">{{ $tag->name }}</span>
                                <x-whatsapp.chat-action size="sm" icon="pencil" :title="__('Edit tag')" wire:click="editTag('{{ $tag->friendly_id }}')" />
                                <x-whatsapp.chat-action size="sm" icon="trash" :title="__('Delete tag')" wire:click="deleteTag('{{ $tag->friendly_id }}')"
                                                        wire:confirm="{{ __('Delete the tag “:name”? It comes off every chat.', ['name' => $tag->name]) }}"
                                                        data-chat-delete-tag="{{ $tag->friendly_id }}" />
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="saveTag" class="flex flex-col gap-3" novalidate data-chat-tag-form>
                <div class="flex items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <x-input :label="$editingTagId !== null ? __('Edit tag') : __('New tag')" id="manage-tag-name" wire:model="tagName" maxlength="{{ \App\Models\WhatsappTag::MAX_NAME_LENGTH }}"
                                 :placeholder="__('Clients, Family…')" data-chat-tag-name />
                    </div>
                    <x-button type="submit" :text="$editingTagId !== null ? __('Save') : __('Add')" icon="check" loading="saveTag" />
                </div>
                @error('tagName') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror
                <fieldset class="flex flex-wrap gap-2">
                    <legend class="sr-only">{{ __('Color') }}</legend>
                    @foreach (\App\Models\WhatsappTarget::PRIORITY_COLORS as $colorName => $hex)
                        <label class="cursor-pointer" x-tooltip="{{ __(ucfirst($colorName)) }}">
                            <input type="radio" name="tag-color" value="{{ $colorName }}" wire:model="tagColor" class="peer sr-only">
                            <span class="block size-6 rounded-full ring-offset-2 ring-offset-secondary-grouped-background peer-checked:ring-2 peer-checked:ring-tint peer-focus-visible:ring-2 peer-focus-visible:ring-tint"
                                  style="background-color: {{ $hex }}"></span>
                            <span class="sr-only">{{ __(ucfirst($colorName)) }}</span>
                        </label>
                    @endforeach
                </fieldset>
            </form>
            </section>
        </div>
    </x-modal>

    <x-modal wire="showPriority" :title="__('Priority')">
        <form wire:submit="savePriority" class="flex flex-col gap-5" novalidate data-chat-priority-form>
            <x-ui.segmented-control name="priority" :label="__('Priority')" wire:model.live="priority" :value="$priority"
                                    :options="['' => __('None'), 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']" />
            <p class="text-xs text-secondary-label">{{ __('1 is the highest.') }}</p>

            @if ($priority !== '')
                <p class="flex items-center gap-2 text-sm text-secondary-label" data-chat-priority-color>
                    <span class="size-4 rounded-full" style="background-color: {{ \App\Models\WhatsappTarget::PRIORITY_COLORS[$priorityPalette[(int) $priority] ?? 'gray'] }}"></span>
                    {{ __('Shown in the color of priority :level.', ['level' => $priority]) }}
                    <a href="{{ route('profile', ['tab' => \App\Livewire\Profile\ProfilePage::TAB_PREFERENCES]) }}#whatsapp-priority-colors" class="text-tint hover:underline" wire:navigate data-chat-priority-colors-link>{{ __('Change the colors') }}</a>
                </p>
            @endif

            @error('priority') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="savePriority" data-chat-priority-save />
            </div>
        </form>
    </x-modal>

    @can('access-module', \App\Models\Module::REMINDERS)
        <x-modal wire="showReminder" :title="__('New reminder')">
            <form wire:submit="createReminder" class="flex flex-col gap-5" novalidate data-chat-reminder-form>
                <x-input wire:model="reminderTitle" :label="__('Title')" maxlength="255" required />
                <x-input type="datetime-local" wire:model="reminderDueAt" :label="__('When')" :hint="__('Optional. Without a date it stays as a task.')" />

                <div class="flex justify-end gap-2">
                    <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                    <x-button type="submit" :text="__('Create')" icon="check" loading="createReminder" data-chat-reminder-save />
                </div>
            </form>
        </x-modal>
    @endcan

    <x-modal wire="showSchedule" :title="__('Schedule message')">
        <form wire:submit="schedule" class="flex flex-col gap-5" novalidate data-chat-schedule-form>
            <x-toggle wire:model.live="scheduleWithAi" :label="__('Written by the AI when it goes out')" data-chat-schedule-with-ai />

            @if ($scheduleWithAi)
                <x-textarea wire:model="schedulePrompt" :label="__('What the AI should write')" rows="4" maxlength="{{ \App\Actions\Whatsapp\ScheduleWhatsappMessage::MAX_AI_PROMPT }}"
                            :placeholder="__('E.g. wish a good morning with a different motivational quote each day')"
                            :hint="__('At each sending the AI writes a new message in your style, reading the latest messages of the chat.')"
                            data-chat-schedule-prompt />
            @else
                <x-textarea wire:model="text" :label="__('Message')" rows="4" maxlength="{{ \App\Actions\Whatsapp\WhatsappMessageFields::MAX_BODY }}"
                            :hint="__('With a file, this is its caption (optional). A voice note goes without text.')" />

                <x-whatsapp.media-picker property="scheduleFile" :file="$scheduleFile" />
            @endif

            <x-ui.recurrence-picker :form="$scheduleRecurrence" />

            @error('schedule')
                <p class="text-sm text-danger" data-chat-schedule-error>{{ $message }}</p>
            @enderror

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Schedule')" icon="clock" loading="schedule" data-chat-schedule-save />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showForward" :title="trans_choice('{1} Forward 1 message|[2,*] Forward :count messages', count($forwardIds), ['count' => count($forwardIds)])">
        <form wire:submit="forward" class="flex flex-col gap-4" novalidate data-chat-forward-form>
            <x-input wire:model.live.debounce.300ms="forwardSearch" :placeholder="__('Search name or number')" icon="magnifying-glass" />

            <ul class="flex max-h-80 flex-col overflow-y-auto rounded-control bg-fill" data-chat-forward-chats>
                @forelse ($this->forwardChats as $target)
                    <li wire:key="forward-{{ $target->friendly_id }}">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-fill-pressed">
                            <input type="checkbox" value="{{ $target->friendly_id }}" wire:model.live="forwardTo"
                                   @disabled(! in_array($target->friendly_id, $forwardTo, true) && count($forwardTo) >= \App\Actions\Whatsapp\ForwardWhatsappChatMessages::MAX_CHATS)
                                   class="size-4 rounded border-separator text-tint focus:ring-tint" data-chat-forward-to="{{ $target->friendly_id }}">
                            <x-whatsapp.avatar :target="$target" size="sm" />
                            <span class="min-w-0 flex-1 truncate text-sm text-label">{{ $target->displayName() }}</span>
                        </label>
                    </li>
                @empty
                    <li class="px-3 py-4 text-sm text-secondary-label">{{ __('No chat found.') }}</li>
                @endforelse
            </ul>

            <p class="text-xs text-secondary-label">{{ __('Up to :max chats. WhatsApp does not show them as forwarded: they go as new messages.', ['max' => \App\Actions\Whatsapp\ForwardWhatsappChatMessages::MAX_CHATS]) }}</p>

            @error('forwardTo') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Forward')" icon="arrow-uturn-right" loading="forward" :disabled="$forwardTo === []" data-chat-forward-send />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showNewChat" :title="__('New chat')">
        <form wire:submit="startChat" class="flex flex-col gap-5" novalidate data-chat-new-form>
            <x-input wire:model="number" :label="__('Number')" :hint="__('In international format, such as +5511999990000.')"
                     inputmode="tel" autocomplete="off" required data-chat-new-number />

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Open chat')" loading="startChat" data-chat-new-submit />
            </div>
        </form>
    </x-modal>
</div>
