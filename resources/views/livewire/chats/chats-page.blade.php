{{--
    The unified chat screen, full window: every channel's conversations on
    the left (channel, sort and tag chips, search; pinned on top with the
    channel's mark and the priority), the open conversation on the right
    with what its channel allows and what Jarvis adds, a thread in a side
    panel. Below 768px only one pane shows at a time.
--}}
@php
    $timezone = auth()->user()->timezone;
    $conversation = $this->conversation;
    $capabilities = $this->capabilities;
    $priorityPalette = $this->priorityPalette;
    $canRemind = auth()->user()->can('access-module', \App\Models\Module::REMINDERS);
    $quickReactions = $capabilities?->reactionEmojis === null ? \App\Livewire\Chats\ChatsPage::QUICK_REACTIONS : array_values(array_intersect(\App\Livewire\Chats\ChatsPage::QUICK_REACTIONS, $capabilities->reactionEmojis));
    $chip = 'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-tint';
    $chipOn = 'bg-chat-bubble-out font-medium text-chat-text';
    $chipOff = 'bg-chat-panel text-chat-meta hover:bg-chat-list-active';
    $menuItem = 'flex w-full items-center gap-3 rounded-control px-3 py-2 text-left text-sm hover:bg-fill focus:bg-fill focus:outline-none disabled:opacity-50';
@endphp

<div class="flex h-full w-full overflow-hidden bg-grouped-background" data-chat-screen>
    <style>
        .chat-body .chat-mention { color: var(--color-chat-link); font-weight: 500; }
        .chat-body .chat-link { color: var(--color-chat-link); text-decoration: underline; }
        .chat-body .chat-code { font-family: ui-monospace, monospace; font-size: 0.875em; background: color-mix(in srgb, currentColor 10%, transparent); border-radius: 0.25rem; padding: 0 0.25rem; }
        .chat-body .chat-pre { font-family: ui-monospace, monospace; font-size: 0.875em; background: color-mix(in srgb, currentColor 10%, transparent); border-radius: 0.375rem; padding: 0.5rem; margin: 0.25rem 0; overflow-x: auto; white-space: pre; }
        .chat-body .chat-quote { border-left: 3px solid var(--color-chat-meta); padding-left: 0.5rem; color: var(--color-chat-meta); margin: 0.25rem 0; }
    </style>

    {{-- Conversations --}}
    <aside @class(['flex w-full shrink-0 flex-col border-r border-chat-divider bg-chat-list text-chat-text md:w-[26rem]', 'hidden md:flex' => $conversation !== null])
           aria-label="{{ __('Conversations') }}" x-data="{
               selecting: false,
               picked: [],
               menu: null,
               {{-- The list is an island the screen does not send again: what to pick comes from its rows. --}}
               pickAll(unread) {
                   this.picked = [...this.$root.querySelectorAll(unread ? '[data-chat-item][data-chat-item-is-unread]' : '[data-chat-item]')].map((row) => row.dataset.chatItem).slice(0, {{ \App\Actions\Chats\ApplyChatConversationsBulkAction::MAX_CONVERSATIONS }})
               },
               openMenu(trigger, item) {
                   if (this.menu?.id === item.id) { this.menu = null; return }
                   const box = trigger.getBoundingClientRect()
                   const upwards = box.bottom + 280 > window.innerHeight
                   this.menu = { ...item, top: upwards ? null : box.bottom + 4, bottom: upwards ? window.innerHeight - box.top + 4 : null, right: window.innerWidth - box.right }
               },
           }">
        <header class="flex flex-col gap-3 px-3 pt-3 pb-2">
            <div class="flex items-center justify-between gap-2">
                <x-ui.brand :href="route('chats.index')" />
                <div class="flex items-center gap-1">
                    <x-whatsapp.chat-action icon="check-circle" :title="__('Select conversations')" x-on:click="selecting = ! selecting; picked = []" x-bind:aria-pressed="selecting.toString()" data-chat-select-toggle />
                    <x-whatsapp.chat-action icon="link" :title="__('Channels')" x-on:click="window.open('{{ route('chats.index') }}', '_blank')" data-chat-channels-link />
                </div>
            </div>

            <x-ui.search-field name="search" :placeholder="__('Search by name')" wire:model.live.debounce.300ms="search" data-chat-search />

            @if ($this->providers->count() > 1)
                <div class="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1" role="group" aria-label="{{ __('Filter by channel') }}" data-chat-provider-filter>
                    @foreach ($this->providers as $provider)
                        <button type="button" wire:click="filterByProvider('{{ $provider->slug }}')" aria-pressed="{{ $providerFilter === $provider->slug ? 'true' : 'false' }}"
                                class="{{ $chip }} {{ $providerFilter === $provider->slug ? $chipOn : $chipOff }}" data-chat-filter-provider="{{ $provider->slug }}">
                            <x-chats.provider-icon :provider="$provider->slug" class="size-3.5" />{{ $provider->name }}
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="-mx-3 flex gap-2 overflow-x-auto px-3 pb-1" role="group" aria-label="{{ __('Sort conversations') }}" data-chat-sort>
                @foreach ([\App\Livewire\Chats\ChatsPage::SORT_RECENT => __('Recent'), \App\Livewire\Chats\ChatsPage::SORT_PRIORITY => __('Priority'), \App\Livewire\Chats\ChatsPage::SORT_AI => __('AI')] as $sortOption => $sortLabel)
                    <button type="button" wire:click="$set('sort', '{{ $sortOption }}')" aria-pressed="{{ $sort === $sortOption ? 'true' : 'false' }}"
                            class="{{ $chip }} {{ $sort === $sortOption ? $chipOn : $chipOff }}" data-chat-sort-option="{{ $sortOption }}">{{ $sortLabel }}</button>
                @endforeach

                @foreach ($this->tags as $tag)
                    <button type="button" wire:click="filterByTag('{{ $tag->friendly_id }}')" aria-pressed="{{ $tagFilter === $tag->friendly_id ? 'true' : 'false' }}"
                            class="{{ $chip }} {{ $tagFilter === $tag->friendly_id ? $chipOn : $chipOff }}" data-chat-filter-tag="{{ $tag->friendly_id }}">
                        <span class="size-2 rounded-full" style="background-color: {{ $tag->hex() }}"></span>{{ $tag->name }}
                    </button>
                @endforeach
            </div>

            @php
                $bulkMax = \App\Actions\Chats\ApplyChatConversationsBulkAction::MAX_CONVERSATIONS;
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
                          x-text="picked.length === 0 ? @js(__('Pick the conversations.')) : @js(__(':count picked (up to :max)', ['max' => $bulkMax])).replace(':count', picked.length)"></span>
                    <button type="button" class="text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="pickAll(false)" data-chat-bulk-all>{{ __('All') }}</button>
                    <button type="button" class="text-tint hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="pickAll(true)" data-chat-bulk-unread>{{ __('All unread') }}</button>
                    <button type="button" class="text-chat-meta hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" x-on:click="selecting = false; picked = []">{{ __('Cancel') }}</button>
                </div>

                <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Actions on the conversations picked') }}">
                    <button type="button" x-bind:disabled="picked.length === 0"
                            x-on:click="$wire.triageConversations(picked).then(() => { selecting = false; picked = [] })"
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
        </header>

        <div class="flex-1 overflow-y-auto" x-on:scroll.passive="menu = null" data-chat-list>
            @island(name: 'conversations')
                @include('livewire.chats.partials.conversation-list')
            @endisland
        </div>

        {{-- The one menu of the rows, put next to the row it was opened from. --}}
        <div x-cloak x-show="menu !== null" x-transition.opacity.duration.100ms role="menu"
             x-on:click.outside="menu = null" x-on:keydown.escape.window="menu = null" x-on:click="menu = null"
             x-bind:style="menu && { top: menu.top !== null ? menu.top + 'px' : 'auto', bottom: menu.bottom !== null ? menu.bottom + 'px' : 'auto', right: menu.right + 'px' }"
             class="fixed z-40 flex w-56 flex-col rounded-card bg-secondary-grouped-background p-1.5 text-label shadow-lg ring-1 ring-separator" data-chat-row-menu>
            <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="$wire.togglePin(menu.id)" data-chat-item-pin>
                <x-icon name="bookmark" class="size-5 text-secondary-label" aria-hidden="true" /><span x-text="menu?.pinned ? @js(__('Unpin')) : @js(__('Pin'))"></span>
            </button>
            <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="$wire.markUnread(menu.id, ! menu.unread)" data-chat-item-unread>
                <x-icon name="envelope" class="size-5 text-secondary-label" aria-hidden="true" /><span x-text="menu?.unread ? @js(__('Mark as read')) : @js(__('Mark as unread'))"></span>
            </button>
            <button type="button" role="menuitem" class="{{ $menuItem }}" x-on:click="$wire.archive(menu.id, ! menu.archived)" data-chat-item-archive>
                <x-icon name="archive-box" class="size-5 text-secondary-label" aria-hidden="true" /><span x-text="menu?.archived ? @js(__('Unarchive')) : @js(__('Archive'))"></span>
            </button>
            <button type="button" role="menuitem" class="{{ $menuItem }}" x-show="menu?.muted" x-on:click="$wire.mute(menu.id, null)" data-chat-item-unmute>
                <x-icon name="bell" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Unmute') }}
            </button>
            @foreach (\App\Actions\Chats\MuteChatConversation::DURATIONS as $duration => $label)
                <button type="button" role="menuitem" class="{{ $menuItem }}" x-show="! menu?.muted" x-on:click="$wire.mute(menu.id, @js($duration))" data-chat-item-mute="{{ $duration }}">
                    <x-icon name="bell-slash" class="size-5 text-secondary-label" aria-hidden="true" />{{ __($label) }}
                </button>
            @endforeach
        </div>
    </aside>

    {{-- Open conversation --}}
    <main @class(['flex min-w-0 flex-1 flex-col bg-chat-wallpaper text-chat-text', 'hidden md:flex' => $conversation === null]) id="main-content">
        @if ($conversation === null)
            <div class="m-auto flex max-w-sm flex-col items-center gap-3 p-6 text-center">
                <x-icon name="chat-bubble-oval-left-ellipsis" class="size-12 text-secondary-label" aria-hidden="true" />
                <p class="text-sm text-secondary-label">{{ __('Pick a conversation on the left.') }}</p>
            </div>
        @else
            <header class="relative flex items-center gap-3 border-b border-separator bg-chat-panel px-3 py-2">
                <div wire:loading.delay wire:target.except="__dispatch, $refresh, text, threadText, search, attachments"
                     class="pointer-events-none absolute inset-x-0 -bottom-px h-0.5 overflow-hidden" role="progressbar" aria-label="{{ __('Working…') }}" data-chat-progress>
                    <div class="chat-progress h-full w-1/3 bg-chat-accent"></div>
                </div>
                <x-whatsapp.chat-action icon="chevron-left" :title="__('Back')" class="md:hidden" wire:click="closeConversation" data-chat-back />

                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <x-whatsapp.avatar :name="$conversation->displayName()" :picture="$conversation->picture_url" wire:key="avatar-{{ $conversation->friendly_id }}" />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="flex items-center gap-2 truncate font-semibold text-label" data-chat-title>
                            <x-chats.provider-icon :provider="$conversation->provider->slug" :title="$conversation->provider->name" class="size-4" />
                            <span class="truncate">{{ $conversation->displayName() }}</span>
                            @if ($conversation->priority !== null)
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[0.6875rem] font-bold text-on-tint" style="background-color: {{ $conversation->priorityHex($priorityPalette) }}">{{ __('Priority :level', ['level' => $conversation->priority]) }}</span>
                            @endif
                        </span>
                        @php
                            $subtitle = array_filter([
                                $conversation->provider->name.' · '.match ($conversation->kind) { 'direct' => __('Direct message'), 'group' => __('Group'), default => __('Channel') },
                                filled($conversation->nickname) ? __('“:name” on the channel', ['name' => $conversation->name]) : null,
                                $conversation->contact !== null ? __('Contact: :name', ['name' => $conversation->contact->name]) : null,
                            ]);
                        @endphp
                        <span class="flex min-w-0 items-center gap-2 truncate text-xs text-secondary-label" data-chat-subtitle>
                            @foreach ($conversation->tags as $tag)
                                <span class="inline-flex shrink-0 items-center gap-1 font-medium text-label" data-chat-tag="{{ $tag->friendly_id }}">
                                    <span class="size-2 rounded-full" style="background-color: {{ $tag->hex() }}"></span>{{ $tag->name }}
                                </span>
                            @endforeach
                            <span class="truncate">{{ implode(' · ', $subtitle) }}</span>
                        </span>
                    </span>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if ($capabilities?->externalLink && ($externalUrl = app(\App\Services\Chats\ChatProviderRegistry::class)->for($conversation)->externalUrl($conversation)) !== null)
                        <a href="{{ $externalUrl }}" target="_blank" rel="noopener" x-tooltip="{{ __('Open on :channel', ['channel' => $conversation->provider->name]) }}" aria-label="{{ __('Open on :channel', ['channel' => $conversation->provider->name]) }}"
                           class="grid size-10 place-items-center rounded-full text-label hover:bg-fill-pressed focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" data-chat-external>
                            <x-icon name="arrow-top-right-on-square" class="size-6" aria-hidden="true" />
                        </a>
                    @endif

                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" data-chat-more-actions>
                        <x-whatsapp.chat-action icon="ellipsis-vertical" :title="__('More actions')" x-on:click="open = ! open" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" />
                        <div x-show="open" x-cloak x-transition.opacity.duration.100ms role="menu" x-on:click="open = false"
                             class="absolute top-full right-0 z-40 mt-1 flex w-64 flex-col rounded-card bg-secondary-grouped-background p-1.5 text-label shadow-lg ring-1 ring-separator">
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="togglePin('{{ $conversation->friendly_id }}')" data-chat-pin>
                                <x-icon name="bookmark" class="size-5 text-secondary-label" aria-hidden="true" />{{ $conversation->isPinned() ? __('Unpin') : __('Pin') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="editPriority" data-chat-priority-open>
                                <x-icon name="flag" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Priority') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="editNickname" data-chat-nickname-open>
                                <x-icon name="pencil-square" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Nickname') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="editTags" data-chat-tags-open>
                                <x-icon name="tag" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Tags') }}
                            </button>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="openContact" data-chat-contact-open>
                                <x-icon name="user-circle" class="size-5 text-secondary-label" aria-hidden="true" />{{ $conversation->contact === null ? __('Link to a contact') : __('Contact') }}
                            </button>
                            @if ($canRemind)
                                <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="startReminder" data-chat-reminder>
                                    <x-icon name="bell-alert" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('New reminder') }}
                                </button>
                            @endif
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="markUnread('{{ $conversation->friendly_id }}', true)" data-chat-mark-unread>
                                <x-icon name="envelope" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Mark as unread') }}
                            </button>
                            @if ($conversation->isMuted())
                                <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="mute('{{ $conversation->friendly_id }}', null)" data-chat-unmute>
                                    <x-icon name="bell" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Unmute') }}
                                </button>
                            @else
                                <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="mute('{{ $conversation->friendly_id }}', '8h')" data-chat-mute>
                                    <x-icon name="bell-slash" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Mute for 8 hours') }}
                                </button>
                            @endif
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="archive('{{ $conversation->friendly_id }}', {{ $conversation->isArchived() ? 'false' : 'true' }})" data-chat-archive>
                                <x-icon name="archive-box" class="size-5 text-secondary-label" aria-hidden="true" />{{ $conversation->isArchived() ? __('Unarchive') : __('Archive') }}
                            </button>
                            <div class="my-1 border-t border-separator"></div>
                            <button type="button" role="menuitem" class="{{ $menuItem }}" wire:click="syncConversation" data-chat-sync>
                                <x-icon name="arrow-path" class="size-5 text-secondary-label" aria-hidden="true" />{{ __('Sync with :channel', ['channel' => $conversation->provider->name]) }}
                            </button>
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

            @if ($this->conversationReminders->isNotEmpty() || $this->scheduled->isNotEmpty())
                <div class="flex flex-col gap-1 border-b border-separator bg-chat-panel px-4 py-2 text-sm">
                    @foreach ($this->conversationReminders as $reminder)
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
                            <x-icon name="clock" class="size-4 shrink-0 text-tint" aria-hidden="true" />
                            <span class="shrink-0 text-xs text-secondary-label" data-chat-scheduled-next>
                                @if ($scheduledMessage->repeats())
                                    <x-icon name="arrow-path" class="inline size-3" :aria-label="__('Repeats')" />
                                @endif
                                {{ $scheduledMessage->nextSendAt()?->setTimezone($timezone)->isoFormat('lll') }}
                            </span>
                            <span class="min-w-0 flex-1 truncate">{{ match (true) { $scheduledMessage->writesWithAi() => '✨ '.$scheduledMessage->ai_prompt, $scheduledMessage->sendsPoll() => '📊 '.$scheduledMessage->body, default => $scheduledMessage->body } }}</span>
                            <button type="button" class="shrink-0 text-xs text-danger hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                                    wire:click="cancelScheduled('{{ $scheduledMessage->friendly_id }}')" wire:confirm="{{ __('Cancel this scheduled message?') }}"
                                    data-chat-cancel-scheduled="{{ $scheduledMessage->friendly_id }}">{{ __('Cancel') }}</button>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="flex min-h-0 flex-1">
                <div class="relative flex min-w-0 flex-1 flex-col"
                     x-data="{
                        keep: null,
                        atBottom: true,
                        scroller() { return this.$refs.scroller },
                        onScroll() { const box = this.scroller(); this.atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80 },
                        toBottom(smooth = false) { this.$nextTick(() => requestAnimationFrame(() => { const box = this.scroller(); box.scrollTo({ top: box.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }); this.atBottom = true })) },
                        received() { if (this.atBottom) { this.toBottom() } },
                     }"
                     x-init="toBottom()"
                     x-on:chat-opened.window="toBottom()" x-on:chat-sent.window="toBottom(true)" x-on:chat-received.window="received()">
                    <button type="button" x-show="! atBottom" x-cloak x-transition.opacity x-on:click="toBottom(true)"
                            aria-label="{{ __('Go to the latest messages') }}" x-tooltip="{{ __('Go to the latest messages') }}"
                            class="absolute right-4 bottom-4 z-20 grid size-11 place-items-center rounded-full bg-chat-bubble-in text-chat-meta shadow-lg ring-1 ring-separator hover:text-chat-text focus:outline-none focus-visible:ring-2 focus-visible:ring-tint"
                            data-chat-to-bottom>
                        <x-icon name="chevron-down" class="size-6" aria-hidden="true" />
                    </button>

                    <div x-ref="scroller" x-on:scroll.passive="onScroll()" class="flex-1 overflow-y-auto px-3 py-4 md:px-12" data-chat-messages>
                        @if ($this->hasOlderMessages())
                            <div class="mb-4 flex justify-center">
                                <x-button :text="__('Load older messages')" flat sm color="secondary" data-chat-load-older
                                          x-on:click="const box = $refs.scroller; keep = box.scrollHeight - box.scrollTop; $wire.loadOlder().then(() => $nextTick(() => box.scrollTop = box.scrollHeight - keep))" />
                            </div>
                        @endif

                        <ol role="list" class="flex flex-col gap-1">
                            @php
                                $previousDay = null;
                                $previousSender = null;
                                $showsSender = ! $conversation->isDirect();
                            @endphp
                            @foreach ($this->messages as $message)
                                @php
                                    $sentAt = $message->sentAt->setTimezone($timezone);
                                    $sender = $message->fromMe ? '' : (string) $message->senderId;
                                    $startsRun = $sentAt->toDateString() !== $previousDay || $sender !== $previousSender;
                                    $previousSender = $sender;
                                @endphp

                                @if ($sentAt->toDateString() !== $previousDay)
                                    @php $previousDay = $sentAt->toDateString(); @endphp
                                    <li class="my-3 flex justify-center" wire:key="day-{{ $previousDay }}">
                                        <span class="rounded-control bg-chat-bubble-in px-3 py-1 text-xs font-medium text-chat-meta uppercase shadow-soft">
                                            {{ $sentAt->isToday() ? __('Today') : ($sentAt->isYesterday() ? __('Yesterday') : $sentAt->isoFormat('LL')) }}
                                        </span>
                                    </li>
                                @endif

                                @include('livewire.chats.partials.message', ['message' => $message, 'startsRun' => $startsRun, 'showsSender' => $showsSender && ! $message->fromMe, 'inThread' => false])
                            @endforeach
                        </ol>

                        @if ($this->messages->isEmpty())
                            <p class="py-12 text-center text-sm text-chat-meta" data-chat-empty>{{ __('No messages here yet.') }}</p>
                        @endif
                    </div>

                    @if ($selected !== [])
                        <div class="flex items-center gap-2 border-t border-separator bg-chat-panel px-3 py-2 text-sm text-label" data-chat-selection>
                            <span class="flex-1">{{ trans_choice('{1} 1 message picked|[2,*] :count messages picked', count($selected), ['count' => count($selected)]) }}</span>
                            <x-button :text="__('Write with AI')" icon="sparkles" sm flat color="secondary" wire:click="startDraft" data-chat-draft-selected />
                            @if ($canRemind)
                                <x-button :text="__('Reminder')" icon="bell-alert" sm flat color="secondary" wire:click="startReminder" data-chat-remind-selected />
                            @endif
                            <x-button :text="__('Clear')" icon="x-mark" sm flat color="secondary" wire:click="clearSelected" />
                        </div>
                    @endif

                    @if ($capabilities?->send)
                        <form wire:submit="send" class="flex flex-col gap-2 bg-chat-panel px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] md:px-4 md:py-2.5" data-chat-composer>
                            @if ($attachments !== [])
                                <ul class="flex flex-wrap gap-2" data-chat-attachments>
                                    @foreach ($attachments as $index => $file)
                                        <li wire:key="attachment-{{ $index }}" class="flex items-center gap-2 rounded-control bg-fill px-2 py-1 text-sm text-label">
                                            <x-icon name="paper-clip" class="size-4 text-secondary-label" aria-hidden="true" />
                                            <span class="max-w-48 truncate">{{ $file->getClientOriginalName() }}</span>
                                            <button type="button" wire:click="removeAttachment({{ $index }})" class="text-secondary-label hover:text-label" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="size-4" /></button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @error('text') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                            @error('files') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                            @error('attachments.*') <p class="text-sm text-danger">{{ $message }}</p> @enderror

                            <div class="flex items-end gap-1.5 md:gap-2">
                                @if ($capabilities->files)
                                    <label class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-full text-label hover:bg-fill-pressed focus-within:ring-2 focus-within:ring-tint" x-tooltip="{{ __('Attach a file') }}">
                                        <x-icon name="paper-clip" class="size-6" aria-hidden="true" />
                                        <input type="file" multiple wire:model="attachments" class="sr-only" data-chat-attach aria-label="{{ __('Attach a file') }}" />
                                    </label>
                                @endif
                                <x-whatsapp.chat-action icon="sparkles" :title="__('Write with AI')" wire:click="startDraft" data-chat-draft />
                                {{-- The dialogs open in the page: asking the server would send the screen again. --}}
                                <x-whatsapp.chat-action icon="clock" :title="__('Schedule')" x-on:click="$wire.scheduleBody = $wire.text; $wire.showSchedule = true" data-chat-schedule />
                                @if ($capabilities->polls)
                                    <x-whatsapp.chat-action icon="chart-bar" :title="__('Poll')" x-on:click="$dispatch('chat-poll-open'); $wire.showPoll = true" data-chat-poll-open />
                                @endif

                                <textarea wire:model="text" rows="1" placeholder="{{ __('Type a message') }}" maxlength="{{ \App\Actions\Chats\SendChatMessage::MAX_LENGTH }}"
                                          x-data="{ grow() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px' } }" x-init="grow()" x-on:input="grow()"
                                          x-on:keydown.enter.prevent="if (! $event.shiftKey) { $el.form.requestSubmit() } else { $el.value += '\n'; $el.dispatchEvent(new Event('input')) }"
                                          class="block max-h-40 min-h-10 w-full resize-none rounded-control border-0 bg-chat-bubble-in px-3 py-2.5 text-[0.9375rem] text-chat-text placeholder:text-chat-meta focus:ring-2 focus:ring-tint focus:outline-none"
                                          data-chat-text></textarea>

                                <button type="submit" class="grid size-10 shrink-0 place-items-center rounded-full bg-chat-accent text-on-tint hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-tint disabled:opacity-50"
                                        wire:loading.attr="disabled" wire:target="send, attachments" aria-label="{{ __('Send') }}" x-tooltip="{{ __('Send') }}" data-chat-send>
                                    <x-icon name="paper-airplane" solid class="size-5" aria-hidden="true" />
                                </button>
                            </div>
                        </form>
                    @else
                        <p class="bg-chat-panel px-4 py-3 text-center text-sm text-secondary-label" data-chat-read-only>{{ __('This conversation is read only here.') }}</p>
                    @endif
                </div>

                {{-- Thread --}}
                @if ($threadId !== '' && $capabilities?->threads)
                    <aside class="flex w-full shrink-0 flex-col border-l border-separator bg-chat-panel md:w-[24rem]" aria-label="{{ __('Thread') }}" data-chat-thread="{{ $threadId }}">
                        <header class="flex items-center gap-2 border-b border-separator px-3 py-2">
                            <span class="flex-1 font-semibold text-label">{{ __('Thread') }}</span>
                            <x-whatsapp.chat-action icon="x-mark" :title="__('Close')" wire:click="closeThread" data-chat-thread-close />
                        </header>
                        <div class="flex-1 overflow-y-auto bg-chat-wallpaper px-3 py-3">
                            <ol role="list" class="flex flex-col gap-1">
                                @foreach ($this->thread as $message)
                                    @include('livewire.chats.partials.message', ['message' => $message, 'startsRun' => true, 'showsSender' => ! $message->fromMe, 'inThread' => true])
                                @endforeach
                            </ol>
                        </div>
                        @if ($capabilities->send)
                            <form wire:submit="sendInThread" class="flex items-end gap-2 px-2 py-2" data-chat-thread-composer>
                                <textarea wire:model="threadText" rows="1" placeholder="{{ __('Reply in the thread') }}" maxlength="{{ \App\Actions\Chats\SendChatMessage::MAX_LENGTH }}"
                                          x-on:keydown.enter.prevent="if (! $event.shiftKey) { $el.form.requestSubmit() }"
                                          class="block max-h-32 min-h-10 w-full resize-none rounded-control border-0 bg-chat-bubble-in px-3 py-2.5 text-[0.9375rem] text-chat-text placeholder:text-chat-meta focus:ring-2 focus:ring-tint focus:outline-none"
                                          data-chat-thread-text></textarea>
                                <button type="submit" class="grid size-10 shrink-0 place-items-center rounded-full bg-chat-accent text-on-tint focus:outline-none focus-visible:ring-2 focus-visible:ring-tint" aria-label="{{ __('Send') }}" data-chat-thread-send>
                                    <x-icon name="paper-airplane" solid class="size-5" aria-hidden="true" />
                                </button>
                            </form>
                        @endif
                    </aside>
                @endif
            </div>
        @endif
    </main>

    {{-- Dialogs --}}
    <x-modal wire="showNickname" :title="__('Nickname')">
        <form wire:submit="saveNickname" class="flex flex-col gap-5" novalidate data-chat-nickname-form>
            <x-input :label="__('Nickname')" wire:model="nickname" maxlength="{{ \App\Models\ChatConversation::MAX_NICKNAME_LENGTH }}"
                     :hint="$conversation !== null ? __('Only you see it, in place of “:name”. Leave it blank to show that name again.', ['name' => $conversation->name]) : null" />
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveNickname" />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showPriority" :title="__('Priority')">
        <form wire:submit="savePriority" class="flex flex-col gap-5" novalidate data-chat-priority-form>
            <x-select.native :label="__('Priority')" wire:model="priority">
                <option value="">{{ __('None') }}</option>
                @for ($level = \App\Models\ChatConversation::MIN_PRIORITY; $level <= \App\Models\ChatConversation::MAX_PRIORITY; $level++)
                    <option value="{{ $level }}">{{ __('Priority :level', ['level' => $level]) }}</option>
                @endfor
            </x-select.native>
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="savePriority" />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showTags" :title="__('Tags')">
        <div class="flex flex-col gap-5" data-chat-tags-form>
            @if ($this->tags->isEmpty())
                <p class="text-sm text-secondary-label">{{ __('No tags yet. Create one below.') }}</p>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($this->tags as $tag)
                        <div wire:key="tag-{{ $tag->friendly_id }}" class="flex items-center gap-2">
                            <x-checkbox wire:model="conversationTags" value="{{ $tag->friendly_id }}" :label="$tag->name" data-chat-tag-option="{{ $tag->friendly_id }}" />
                            <span class="size-3 rounded-full" style="background-color: {{ $tag->hex() }}"></span>
                            <span class="flex-1"></span>
                            <x-button.circle icon="pencil" flat color="secondary" sm :title="__('Edit')" wire:click="editTag('{{ $tag->friendly_id }}')" />
                            <x-button.circle icon="trash" flat color="red" sm :title="__('Delete')" wire:click="deleteTag('{{ $tag->friendly_id }}')" wire:confirm="{{ __('Delete this tag from every conversation?') }}" data-chat-tag-delete="{{ $tag->friendly_id }}" />
                        </div>
                    @endforeach
                </div>
            @endif

            <form wire:submit="saveTag" class="flex flex-col gap-3 rounded-card bg-fill p-3" novalidate data-chat-tag-editor>
                <p class="text-sm font-medium text-label">{{ $editingTagId === null ? __('New tag') : __('Edit tag') }}</p>
                {{-- Its own id: the default one, `tagName`, would hide the form's `tagName` from Livewire's morph and break every update. --}}
                <x-input :label="__('Name')" id="inbox-tag-name" wire:model="tagName" maxlength="{{ \App\Models\ChatTag::MAX_NAME_LENGTH }}" data-chat-tag-name />
                <x-select.native :label="__('Color')" wire:model="tagColor" data-chat-tag-color>
                    @foreach (array_keys(\App\Models\WhatsappTarget::PRIORITY_COLORS) as $colorName)
                        <option value="{{ $colorName }}">{{ __(ucfirst($colorName)) }}</option>
                    @endforeach
                </x-select.native>
                <div class="flex justify-end">
                    <x-button type="submit" :text="$editingTagId === null ? __('Create') : __('Save')" icon="check" sm loading="saveTag" data-chat-tag-save />
                </div>
            </form>

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button :text="__('Apply')" icon="check" wire:click="saveConversationTags" loading="saveConversationTags" data-chat-tags-save />
            </div>
        </div>
    </x-modal>

    <x-modal wire="showContact" :title="__('Contact')">
        <div class="flex flex-col gap-5" data-chat-contact-form>
            @if ($conversation?->contact !== null)
                <div class="flex items-center gap-3 rounded-card bg-fill p-3">
                    <x-icon name="user-circle" class="size-8 text-secondary-label" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-label">{{ $conversation->contact->name }}</p>
                        <p class="text-xs text-secondary-label">{{ trans_choice('{1} 1 conversation linked|[2,*] :count conversations linked', $conversation->contact->conversations()->count(), ['count' => $conversation->contact->conversations()->count()]) }}</p>
                    </div>
                    <x-button :text="__('Unlink')" icon="link-slash" sm flat color="red" wire:click="unlinkContact" data-chat-contact-unlink />
                </div>
            @endif

            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-label">{{ __('Same person as another conversation') }}</p>
                <x-ui.search-field name="contactSearch" :placeholder="__('Search a conversation')" wire:model.live.debounce.300ms="contactSearch" data-chat-contact-search />
                <ul role="list" class="divide-y divide-separator">
                    @foreach ($this->linkCandidates as $candidate)
                        <li wire:key="candidate-{{ $candidate->friendly_id }}">
                            <button type="button" wire:click="linkToConversation('{{ $candidate->friendly_id }}')" class="flex w-full items-center gap-3 py-2 text-left hover:bg-fill focus:outline-none focus-visible:bg-fill" data-chat-link-candidate="{{ $candidate->friendly_id }}">
                                <x-chats.provider-icon :provider="$candidate->provider->slug" class="size-4" />
                                <span class="min-w-0 flex-1 truncate text-sm text-label">{{ $candidate->displayName() }}</span>
                                @if ($candidate->contact !== null)
                                    <span class="shrink-0 text-xs text-secondary-label">{{ $candidate->contact->name }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>

            <form wire:submit="createContact" class="flex items-end gap-2" novalidate>
                <div class="flex-1">
                    <x-input :label="__('Or a new contact')" wire:model="contactName" maxlength="{{ \App\Models\ChatContact::MAX_NAME_LENGTH }}" data-chat-contact-name />
                </div>
                <x-button type="submit" :text="__('Link')" icon="link" loading="createContact" data-chat-contact-create />
            </form>
        </div>
    </x-modal>

    <x-modal wire="showReminder" :title="__('New reminder')">
        <form wire:submit="createReminder" class="flex flex-col gap-5" novalidate data-chat-reminder-form>
            <x-input :label="__('Title')" wire:model="reminderTitle" maxlength="200" required />
            <x-input type="datetime-local" :label="__('When')" wire:model="reminderDueAt" data-chat-reminder-due />
            @error('message') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Create')" icon="check" loading="createReminder" data-chat-reminder-save />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showSchedule" :title="__('Schedule a message')">
        <form wire:submit="schedule" class="flex flex-col gap-5" novalidate data-chat-schedule-form>
            <x-toggle wire:model.live="scheduleWithAi" :label="__('Written by the AI when it goes out')" data-chat-schedule-with-ai />

            @if ($scheduleWithAi)
                <x-textarea wire:model="schedulePrompt" :label="__('What the AI should write')" rows="4" maxlength="{{ \App\Models\ChatScheduledMessage::MAX_AI_PROMPT_LENGTH }}"
                            :placeholder="__('E.g. wish a good morning with a different motivational quote each day')"
                            :hint="__('At each sending the AI writes a new message in your style, reading the latest messages of the conversation.')"
                            data-chat-schedule-prompt />
            @else
                <x-textarea :label="__('Message')" wire:model="scheduleBody" rows="4" maxlength="{{ \App\Models\ChatScheduledMessage::MAX_BODY_LENGTH }}" data-chat-schedule-body />
            @endif

            <x-ui.recurrence-picker :form="$scheduleRecurrence" />

            @error('thread_id') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Schedule')" icon="clock" loading="schedule" data-chat-schedule-save />
            </div>
        </form>
    </x-modal>

    {{-- What is typed stays in the page until it is sent or scheduled (submitPoll()). --}}
    <x-modal wire="showPoll" :title="__('Poll')">
        @php
            $pollField = 'block w-full rounded-control border-0 bg-fill px-3 py-2 text-sm text-label placeholder:text-secondary-label focus:ring-2 focus:ring-tint focus:outline-none';
            $minOptions = \App\Actions\Whatsapp\SendWhatsappChatContent::MIN_POLL_OPTIONS;
            $maxOptions = \App\Actions\Whatsapp\SendWhatsappChatContent::MAX_POLL_OPTIONS;
        @endphp
        <form x-data="{ question: '', options: Array({{ $minOptions }}).fill(''), multiple: false, schedules: false }"
              x-on:chat-poll-open.window="question = ''; options = Array({{ $minOptions }}).fill(''); multiple = false; schedules = false"
              x-on:submit.prevent="$wire.submitPoll(question, options, multiple, schedules)"
              class="flex flex-col gap-5" novalidate data-chat-poll-form>
            <div class="flex flex-col gap-1.5">
                <label for="chat-poll-question" class="text-sm font-medium text-label">{{ __('Question') }}</label>
                <input id="chat-poll-question" type="text" x-model="question" maxlength="255" class="{{ $pollField }}" data-chat-poll-question />
                @error('pollQuestion') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-sm font-medium text-label">{{ __('Options') }}</legend>
                <template x-for="(option, index) in options" :key="index">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="options[index]" maxlength="100" class="{{ $pollField }}"
                               x-bind:placeholder="@js(__('Option :number')).replace(':number', index + 1)" x-bind:aria-label="@js(__('Option :number')).replace(':number', index + 1)"
                               x-bind:data-chat-poll-input="index" />
                        <x-whatsapp.chat-action size="sm" icon="x-mark" :title="__('Remove')" x-show="options.length > {{ $minOptions }}" x-on:click="options.splice(index, 1)" />
                    </div>
                </template>
                @error('pollOptions') <p role="alert" class="text-xs text-danger">{{ $message }}</p> @enderror
                @foreach ($errors->get('pollOptions.*') as $optionErrors)
                    <p role="alert" class="text-xs text-danger">{{ $optionErrors[0] }}</p>
                @endforeach
                <div x-show="options.length < {{ $maxOptions }}">
                    <x-button type="button" :text="__('Add an option')" icon="plus" flat sm color="secondary" x-on:click="options.push('')" data-chat-poll-add />
                </div>
            </fieldset>

            <label class="flex items-center gap-3 text-sm text-label">
                <input type="checkbox" x-model="multiple" class="rounded border-separator text-tint focus:ring-tint" data-chat-poll-multiple />{{ __('Allow more than one answer') }}
            </label>
            <label class="flex items-center gap-3 text-sm text-label">
                <input type="checkbox" x-model="schedules" class="rounded border-separator text-tint focus:ring-tint" data-chat-poll-schedules />{{ __('Schedule instead of sending now') }}
            </label>

            <div x-show="schedules" x-cloak>
                <x-ui.recurrence-picker :form="$pollRecurrence" />
            </div>

            @error('poll') <p role="alert" class="text-sm text-danger" data-chat-poll-error>{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" icon="paper-airplane" loading="submitPoll" data-chat-poll-submit>
                    <span x-text="schedules ? @js(__('Schedule poll')) : @js(__('Send poll'))"></span>
                </x-button>
            </div>
        </form>
    </x-modal>

    <x-modal wire="showDraft" :title="__('Write with AI')">
        <form wire:submit="writeDraft" class="flex flex-col gap-5" novalidate data-chat-draft-form>
            <p class="text-sm text-secondary-label">
                {{ $selected === [] ? __('The AI answers the latest messages received, in your style. Say what the answer must say, or leave it blank.') : trans_choice('{1} The AI answers the picked message, in your style.|[2,*] The AI answers the :count picked messages together, in your style.', count($selected), ['count' => count($selected)]) }}
            </p>
            <x-textarea :label="__('What it must say')" wire:model="draftInstruction" rows="3" maxlength="{{ \App\Actions\Chats\DraftChatReply::MAX_INSTRUCTION }}" data-chat-draft-instruction />
            @error('message') <p class="text-sm text-danger">{{ $message }}</p> @enderror

            @if ($draft !== '')
                <div class="rounded-card bg-fill p-3 text-sm whitespace-pre-wrap text-label" data-chat-draft-result>{{ $draft }}</div>
            @endif

            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="$draft === '' ? __('Write') : __('Write again')" icon="sparkles" loading="writeDraft" data-chat-draft-write />
                @if ($draft !== '')
                    <x-button type="button" :text="__('Use message')" icon="check" wire:click="useDraft" data-chat-draft-use />
                @endif
            </div>
        </form>
    </x-modal>

    <x-modal wire="showEdit" :title="__('Edit message')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5" novalidate data-chat-edit-form>
            <x-textarea :label="__('Message')" wire:model="editText" rows="4" maxlength="{{ \App\Actions\Chats\SendChatMessage::MAX_LENGTH }}" data-chat-edit-text />
            <div class="flex justify-end gap-2">
                <x-button type="button" :text="__('Cancel')" flat color="secondary" x-on:click="show = false" />
                <x-button type="submit" :text="__('Save')" icon="check" loading="saveEdit" data-chat-edit-save />
            </div>
        </form>
    </x-modal>

    <x-modal wire="showEmoji" :title="__('React')">
        <div class="grid max-h-80 grid-cols-8 gap-1 overflow-y-auto" data-chat-emoji-grid>
            @foreach ($capabilities?->reactionEmojis ?? \App\Livewire\Chats\ChatsPage::QUICK_REACTIONS as $emoji)
                <button type="button" wire:key="emoji-{{ md5($emoji) }}" wire:click="react('{{ $emojiMessageId }}', '{{ $emoji }}', true)"
                        class="grid size-10 place-items-center rounded-control text-2xl hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint">{{ $emoji }}</button>
            @endforeach
        </div>
    </x-modal>
</div>
