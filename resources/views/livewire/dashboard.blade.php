{{--
    The home screen: the greeting, a tile per count that needs the user,
    then the next commitments, the latest unread chats and the shopping
    lists to finish — each only with its module — and a shortcut per
    module at the bottom. Without any module, only the empty state.
--}}
@php
    $user = auth()->user();
    $timezone = $user->timezone;
    $locale = ['locale' => app()->getLocale()];
    $today = now($timezone)->format('Y-m-d');
    $tomorrow = now($timezone)->addDay()->format('Y-m-d');
    $hasAgenda = $user->hasModule(\App\Models\Module::REMINDERS);
    $hasMessages = $user->hasModule(\App\Models\Module::WHATSAPP) || $user->hasModule(\App\Models\Module::CHATS);
    $hasShopping = $user->hasModule(\App\Models\Module::SHOPPING);
@endphp

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    @if (count($this->modules) === 0)
        <x-ui.empty-state icon="squares-2x2"
                          :title="__('No modules yet')"
                          :description="__('Ask the admin to enable a module')" />
    @else
        <div class="flex flex-col gap-0.5" data-dashboard-greeting>
            <p class="text-[1.375rem] leading-tight font-semibold tracking-tight text-label">{{ $this->greeting['greeting'] }}</p>
            <p class="text-[0.9375rem] text-secondary-label" data-dashboard-date>{{ $this->greeting['date'] }}</p>
        </div>

        @if (count($this->stats) > 0)
            <ul role="list" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-dashboard-stats>
                @foreach ($this->stats as $stat)
                    <li wire:key="stat-{{ $stat['key'] }}">
                        <a href="{{ $stat['url'] }}"
                           class="flex h-full flex-col gap-2 rounded-card bg-secondary-grouped-background p-3.5 shadow-soft transition hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint active:scale-[0.99]"
                           data-dashboard-stat="{{ $stat['key'] }}" data-count="{{ $stat['count'] }}">
                            <span class="flex items-center justify-between gap-2">
                                <span aria-hidden="true" @class([
                                    'grid size-8 place-items-center rounded-full',
                                    'bg-tint-fill text-on-tint' => $stat['tone'] === 'tint' || $stat['count'] === 0,
                                    'bg-danger text-on-tint' => $stat['tone'] === 'danger' && $stat['count'] > 0,
                                    'bg-warning text-on-tint' => $stat['tone'] === 'warning' && $stat['count'] > 0,
                                ])>
                                    <x-icon :name="$stat['icon']" class="size-4.5" />
                                </span>
                                <span class="text-[1.625rem] leading-none font-bold tabular-nums text-label">{{ $stat['count'] }}</span>
                            </span>
                            <span class="truncate text-[0.8125rem] font-medium text-secondary-label">{{ $stat['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($hasAgenda || $hasMessages)
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                @if ($hasAgenda)
                    <x-ui.inset-grouped-list data-dashboard-commitments>
                        <x-slot:header>
                            <div class="flex items-baseline justify-between gap-3">
                                <h2 class="font-normal">{{ __('Next commitments') }}</h2>
                                <a href="{{ route('reminders.index') }}" class="text-tint normal-case hover:underline focus:outline-none focus-visible:underline" data-dashboard-link="agenda">{{ __('See agenda') }}</a>
                            </div>
                        </x-slot:header>

                        @foreach ($this->overdueReminders as $occurrence)
                            <x-ui.list-row wire:key="overdue-{{ $occurrence->friendly_id }}"
                                           :href="route('reminders.index', ['view' => \App\Livewire\Agenda\AgendaPage::VIEW_TASKS, 'filter' => \App\Services\Reminders\ReminderOccurrenceFeed::OVERDUE])"
                                           :title="$occurrence->reminder->title"
                                           data-dashboard-overdue="{{ $occurrence->friendly_id }}">
                                <x-slot:leading>
                                    <span aria-hidden="true" class="grid size-8 place-items-center rounded-full bg-danger/15 text-danger">
                                        <x-icon name="exclamation-circle" class="size-5" />
                                    </span>
                                </x-slot:leading>

                                <p class="truncate text-[0.8125rem] text-danger">
                                    <x-ui.localized-date :value="$occurrence->due_at" :format="$occurrence->reminder->is_all_day ? 'date' : 'datetime'" />
                                </p>

                                <x-slot:trailing>
                                    <x-ui.status-badge status="late" :label="__('Late')" tone="danger" />
                                </x-slot:trailing>
                            </x-ui.list-row>
                        @endforeach

                        @forelse ($this->nextCommitments as $entry)
                            @php
                                $day = $entry->day($timezone);
                                $dayLabel = match ($day) {
                                    $today => __('Today'),
                                    $tomorrow => __('Tomorrow'),
                                    default => ucfirst($entry->startsAt->setTimezone($timezone)->settings($locale)->isoFormat('dddd')),
                                };
                                $color = match ($entry->source) {
                                    'events' => 'bg-calendar-event',
                                    'invoices' => 'bg-calendar-invoice',
                                    'whatsapp' => 'bg-calendar-whatsapp',
                                    'shopping' => 'bg-calendar-shopping',
                                    'google' => 'bg-calendar-google',
                                    default => 'bg-calendar-reminder',
                                };
                            @endphp

                            <x-ui.list-row wire:key="next-{{ $entry->key }}"
                                           :href="$entry->url ?? route('reminders.index', ['view' => \App\Livewire\Agenda\AgendaPage::VIEW_DAY, 'date' => $day])"
                                           :title="$entry->title"
                                           :subtitle="$entry->subtitle"
                                           data-dashboard-next="{{ $entry->key }}" data-source="{{ $entry->source }}">
                                <x-slot:leading>
                                    <span class="flex w-16 items-center gap-2">
                                        <span aria-hidden="true" @class(['size-2.5 shrink-0 rounded-full', $color, 'opacity-50' => $entry->isProjected])></span>
                                        <span class="flex min-w-0 flex-col leading-tight">
                                            <span class="truncate text-[0.6875rem] font-semibold text-secondary-label uppercase">{{ $dayLabel }}</span>
                                            <span class="text-[0.8125rem] tabular-nums text-label">{{ $entry->isAllDay ? __('All day') : $entry->startsAt->setTimezone($timezone)->format('H:i') }}</span>
                                        </span>
                                    </span>
                                </x-slot:leading>
                            </x-ui.list-row>
                        @empty
                            @if ($this->overdueReminders->isEmpty())
                                <li class="px-4 py-6 text-center text-[0.9375rem] text-secondary-label" data-dashboard-commitments-empty>
                                    {{ __('Nothing on the agenda for the next 7 days.') }}
                                </li>
                            @endif
                        @endforelse
                    </x-ui.inset-grouped-list>
                @endif

                @if ($hasMessages)
                    <x-ui.inset-grouped-list data-dashboard-messages>
                        <x-slot:header>
                            <div class="flex items-baseline justify-between gap-3">
                                <h2 class="font-normal">{{ __('Latest messages') }}</h2>
                                <a href="{{ $this->chatsUrl() }}" class="text-tint normal-case hover:underline focus:outline-none focus-visible:underline" data-dashboard-link="chats">{{ __('Open chats') }}</a>
                            </div>
                        </x-slot:header>

                        @forelse ($this->latestMessages as $message)
                            @php
                                $at = $message['at']->setTimezone($timezone);
                            @endphp

                            <x-ui.list-row wire:key="message-{{ $message['key'] }}"
                                           :href="$message['url']"
                                           :chevron="false"
                                           data-dashboard-message="{{ $message['key'] }}" data-provider="{{ $message['provider'] }}">
                                <x-slot:leading>
                                    <span class="relative block">
                                        @if ($message['target'] !== null)
                                            <x-whatsapp.avatar :target="$message['target']" size="md" />
                                        @else
                                            <x-whatsapp.avatar :name="$message['name']" :picture="$message['picture']" size="md" />
                                        @endif
                                        <span class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full bg-secondary-grouped-background ring-2 ring-secondary-grouped-background">
                                            <x-chats.provider-icon :provider="$message['provider']" class="size-3.5" />
                                        </span>
                                    </span>
                                </x-slot:leading>

                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-[0.9375rem] font-semibold">{{ $message['name'] }}</span>
                                    <span class="shrink-0 text-xs text-tint tabular-nums">{{ $at->isToday() ? $at->format('H:i') : $at->settings($locale)->isoFormat('L') }}</span>
                                </span>

                                <span class="flex items-center gap-2">
                                    <span class="flex min-w-0 flex-1 items-center gap-1 text-[0.8125rem] text-secondary-label" data-dashboard-message-preview>
                                        @if ($message['fromMe'])
                                            <span class="shrink-0 font-medium">{{ __('You') }}:</span>
                                        @elseif (filled($message['sender']))
                                            <span class="shrink-0 font-medium">{{ $message['sender'] }}:</span>
                                        @endif
                                        @if ($message['icon'] !== null)
                                            <x-icon :name="$message['icon']" class="size-4 shrink-0" aria-hidden="true" />
                                        @endif
                                        <span class="truncate">{{ $message['preview'] }}</span>
                                    </span>

                                    @if ($message['urgency'] !== null)
                                        @php
                                            $aiLook = \App\Models\WhatsappTarget::AI_URGENCY_LOOKS[$message['urgency']];
                                            $aiHex = \App\Models\WhatsappTarget::PRIORITY_COLORS[$aiLook['color']];
                                        @endphp
                                        <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 text-[0.6875rem] font-medium"
                                              style="color: {{ $aiHex }}; background-color: {{ $aiHex }}26"
                                              aria-label="{{ __('AI: :urgency', ['urgency' => __($aiLook['label'])]) }}"
                                              data-dashboard-ai-urgency="{{ $message['urgency'] }}">
                                            <x-icon name="sparkles" class="size-3" aria-hidden="true" />{{ __($aiLook['label']) }}
                                        </span>
                                    @endif

                                    @if ($message['unread'] > 0)
                                        <span class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-tint-fill px-1.5 text-xs font-semibold text-on-tint"
                                              aria-label="{{ trans_choice('{1} 1 unread message|[2,*] :count unread messages', $message['unread'], ['count' => $message['unread']]) }}"
                                              data-dashboard-message-unread="{{ $message['unread'] }}">{{ $message['unread'] }}</span>
                                    @else
                                        <span class="size-3 shrink-0 rounded-full bg-tint-fill" aria-label="{{ __('Marked as unread') }}"></span>
                                    @endif
                                </span>
                            </x-ui.list-row>
                        @empty
                            <li class="px-4 py-6 text-center text-[0.9375rem] text-secondary-label" data-dashboard-messages-empty>
                                {{ __('No unread messages.') }}
                            </li>
                        @endforelse
                    </x-ui.inset-grouped-list>
                @endif
            </div>
        @endif

        @if ($hasShopping && $this->shoppingLists->isNotEmpty())
            <x-ui.inset-grouped-list data-dashboard-shopping>
                <x-slot:header>
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="font-normal">{{ __('Shopping') }}</h2>
                        <a href="{{ route('shopping.index') }}" class="text-tint normal-case hover:underline focus:outline-none focus-visible:underline" data-dashboard-link="shopping">{{ __('See all') }}</a>
                    </div>
                </x-slot:header>

                @foreach ($this->shoppingLists as $list)
                    <x-ui.list-row wire:key="shopping-{{ $list->friendly_id }}"
                                   :href="route('shopping.show', $list)"
                                   wire:navigate
                                   icon="shopping-cart"
                                   :title="$list->name"
                                   :subtitle="collect([
                                       __(':open of :total to pick', ['open' => $list->unchecked_items_count, 'total' => $list->items_count]),
                                       $list->isOverdue() ? __('Overdue') : null,
                                   ])->filter()->join(' · ')"
                                   data-dashboard-shopping-list="{{ $list->friendly_id }}" />
                @endforeach
            </x-ui.inset-grouped-list>
        @endif

        <section class="flex flex-col gap-1.5" aria-labelledby="dashboard-modules">
            <h2 id="dashboard-modules" class="px-4 text-[0.8125rem] font-normal text-secondary-label uppercase">{{ __('Modules') }}</h2>

            <ul role="list" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($this->modules as $module)
                    @php($url = $module->url())

                    <li wire:key="module-{{ $module->key }}" data-module-card="{{ $module->key }}">
                        <{{ $url !== null ? 'a' : 'div' }} @if ($url !== null) href="{{ $url }}" @else aria-disabled="true" @endif
                            @if ($url === null) title="{{ __('Coming soon') }}" @endif
                            @class([
                                'flex min-h-11 items-center gap-2.5 rounded-control bg-secondary-grouped-background px-3 py-2.5 shadow-soft',
                                'transition hover:bg-fill focus:outline-none focus-visible:ring-2 focus-visible:ring-tint active:scale-[0.99]' => $url !== null,
                                'opacity-60' => $url === null,
                            ])>
                            <span aria-hidden="true" class="grid size-7 shrink-0 place-items-center rounded-control bg-tint-fill text-on-tint">
                                <x-icon :name="$module->icon" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[0.9375rem] font-medium text-label">{{ $module->label }}</span>
                            <span class="sr-only">{{ $url !== null ? __('Open :module', ['module' => $module->label]) : __('Coming soon') }}</span>
                        </{{ $url !== null ? 'a' : 'div' }}>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
