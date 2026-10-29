<?php

namespace App\Livewire;

use App\Livewire\Agenda\AgendaPage;
use App\Models\ChatConversation;
use App\Models\ChatProvider;
use App\Models\Invoice;
use App\Models\InvoiceStatus;
use App\Models\Module;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Chats\ChatProviderRegistry;
use App\Services\Reminders\ReminderOccurrenceFeed;
use App\Support\Navigation\AppNavigation;
use App\Support\Navigation\NavigationItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The start page of the signed-in area (`/`, US-2.1): what needs the user
 * now, each part only with its module —
 *
 * - a greeting by the time of day and today's date, in the user's timezone;
 * - tiles counting the unread chats, today's commitments, the overdue
 *   reminders, the items still to buy and the invoices awaiting action,
 *   each opening its screen;
 * - the next commitments of the coming NEXT_DAYS days (CalendarFeed), the
 *   latest overdue reminders on top;
 * - the latest unread chats, WhatsApp and the other chat channels together;
 * - the open shopping lists with items still to pick;
 * - a shortcut per module, or an empty state asking the admin to enable one.
 *
 * @property-read list<NavigationItem> $modules
 * @property-read array{greeting: string, date: string} $greeting
 * @property-read list<array{key: string, label: string, count: int, icon: string, url: string, tone: string}> $stats
 * @property-read list<CalendarEntry> $upcomingEntries
 * @property-read list<CalendarEntry> $nextCommitments
 * @property-read Collection<int, ReminderOccurrence> $overdueReminders
 * @property-read int $overdueCount
 * @property-read int $todayCount
 * @property-read list<array{key: string, provider: string, name: string, target: WhatsappTarget|null, picture: string|null, icon: string|null, preview: string|null, fromMe: bool, sender: string|null, at: CarbonImmutable, unread: int, urgency: string|null, url: string}> $latestMessages
 * @property-read int $unreadWhatsappCount
 * @property-read int $unreadChatsCount
 * @property-read Collection<int, ShoppingList> $shoppingLists
 * @property-read int $openShoppingItemsCount
 * @property-read int $invoicesAwaitingCount
 */
#[Layout('layouts::app-shell')]
class Dashboard extends Component
{
    /**
     * How many days ahead "Next commitments" looks, after today.
     */
    public const int NEXT_DAYS = 7;

    public const int NEXT_SHOWN = 6;

    public const int OVERDUE_SHOWN = 3;

    public const int MESSAGES_SHOWN = 6;

    public const int SHOPPING_LISTS_SHOWN = 3;

    /**
     * The user's modules, in menu order.
     *
     * @return list<NavigationItem>
     */
    #[Computed]
    public function modules(): array
    {
        return AppNavigation::modulesFor($this->user());
    }

    /**
     * "Good morning, Ana" by the hour in the user's timezone, and today's
     * date in their language.
     *
     * @return array{greeting: string, date: string}
     */
    #[Computed]
    public function greeting(): array
    {
        $now = $this->now();
        $name = Str::before(mb_trim($this->user()->name), ' ');

        $greeting = match (true) {
            $now->hour >= 5 && $now->hour < 12 => __('Good morning, :name', ['name' => $name]),
            $now->hour >= 12 && $now->hour < 18 => __('Good afternoon, :name', ['name' => $name]),
            default => __('Good evening, :name', ['name' => $name]),
        };

        return [
            'greeting' => $greeting,
            'date' => ucfirst($now->settings(['locale' => app()->getLocale()])->isoFormat('dddd, LL')),
        ];
    }

    /**
     * The tiles of the modules the user has, those with nothing to count
     * included (a zero says all is done).
     *
     * @return list<array{key: string, label: string, count: int, icon: string, url: string, tone: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $user = $this->user();
        $stats = [];

        if ($user->hasModule(Module::WHATSAPP) || $user->hasModule(Module::CHATS)) {
            $stats[] = [
                'key' => 'unread-chats',
                'label' => __('Unread chats'),
                'count' => $this->unreadWhatsappCount + $this->unreadChatsCount,
                'icon' => 'chat-bubble-left-right',
                'url' => $this->chatsUrl(),
                'tone' => 'tint',
            ];
        }

        if ($user->hasModule(Module::REMINDERS)) {
            $stats[] = [
                'key' => 'today',
                'label' => __('Today'),
                'count' => $this->todayCount,
                'icon' => 'calendar-days',
                'url' => route('reminders.index', ['view' => AgendaPage::VIEW_DAY]),
                'tone' => 'tint',
            ];
            $stats[] = [
                'key' => 'overdue',
                'label' => __('Overdue'),
                'count' => $this->overdueCount,
                'icon' => 'exclamation-circle',
                'url' => route('reminders.index', ['view' => AgendaPage::VIEW_TASKS, 'filter' => ReminderOccurrenceFeed::OVERDUE]),
                'tone' => 'danger',
            ];
        }

        if ($user->hasModule(Module::SHOPPING)) {
            $stats[] = [
                'key' => 'shopping',
                'label' => __('To buy'),
                'count' => $this->openShoppingItemsCount,
                'icon' => 'shopping-cart',
                'url' => route('shopping.index'),
                'tone' => 'tint',
            ];
        }

        if ($user->hasModule(Module::INVOICES)) {
            $stats[] = [
                'key' => 'invoices',
                'label' => __('Invoices to review'),
                'count' => $this->invoicesAwaitingCount,
                'icon' => 'document-text',
                'url' => route('invoices.history.index'),
                'tone' => 'warning',
            ];
        }

        return $stats;
    }

    /**
     * Everything on the agenda from the start of today to the end of the
     * NEXT_DAYS-th day after it, all-day ones of a day first.
     *
     * @return list<CalendarEntry>
     */
    #[Computed]
    public function upcomingEntries(): array
    {
        if (! $this->user()->hasModule(Module::REMINDERS)) {
            return [];
        }

        $today = $this->now()->startOfDay();

        return app(CalendarFeed::class)->between($this->user(), $today->utc(), $today->addDays(self::NEXT_DAYS + 1)->utc());
    }

    /**
     * How many open entries today has, the overdue ones of today included.
     */
    #[Computed]
    public function todayCount(): int
    {
        $today = $this->now()->format('Y-m-d');
        $timezone = $this->user()->timezone;

        return count(array_filter($this->upcomingEntries, fn (CalendarEntry $entry): bool => ! $entry->isCompleted() && $entry->day($timezone) === $today));
    }

    /**
     * The first NEXT_SHOWN entries still to come: starting from now, the
     * all-day ones of today included, not completed, and not one of the
     * overdue reminders shown above them.
     *
     * @return list<CalendarEntry>
     */
    #[Computed]
    public function nextCommitments(): array
    {
        $now = CarbonImmutable::now();
        $today = $this->now()->format('Y-m-d');
        $timezone = $this->user()->timezone;
        $overdueIds = $this->overdueReminders->modelKeys();

        $next = array_filter($this->upcomingEntries, fn (CalendarEntry $entry): bool => ! $entry->isCompleted()
            && ! in_array($entry->occurrence?->id, $overdueIds, true)
            && ($entry->startsAt->greaterThanOrEqualTo($now) || ($entry->isAllDay && $entry->day($timezone) === $today)));

        return array_slice(array_values($next), 0, self::NEXT_SHOWN);
    }

    /**
     * The latest OVERDUE_SHOWN open reminders whose time has passed, with
     * their reminder.
     *
     * @return Collection<int, ReminderOccurrence>
     */
    #[Computed]
    public function overdueReminders(): Collection
    {
        if (! $this->user()->hasModule(Module::REMINDERS)) {
            return new Collection;
        }

        return $this->overdueQuery()
            ->with('reminder')
            ->orderByDesc('due_at')
            ->orderByDesc('id')
            ->limit(self::OVERDUE_SHOWN)
            ->get();
    }

    #[Computed]
    public function overdueCount(): int
    {
        return $this->user()->hasModule(Module::REMINDERS) ? $this->overdueQuery()->count() : 0;
    }

    /**
     * The newest MESSAGES_SHOWN unread chats, WhatsApp and the other
     * channels together, each as one row of the list.
     *
     * @return list<array{key: string, provider: string, name: string, target: WhatsappTarget|null, picture: string|null, icon: string|null, preview: string|null, fromMe: bool, sender: string|null, at: CarbonImmutable, unread: int, urgency: string|null, url: string}>
     */
    #[Computed]
    public function latestMessages(): array
    {
        $rows = [];

        // A WhatsApp chat opens in the inbox when it is there too.
        $inbox = $this->user()->hasModule(Module::CHATS) && $this->user()->hasChatProvider(ChatProvider::WHATSAPP);

        if ($this->user()->hasModule(Module::WHATSAPP)) {
            $targets = $this->unreadWhatsappQuery()
                ->with(['lastChatMessage', 'account', 'conversation'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit(self::MESSAGES_SHOWN)
                ->get();

            foreach ($targets as $target) {
                $last = $target->lastChatMessage;
                $summary = $last?->summary();

                $rows[] = [
                    'key' => $target->friendly_id,
                    'provider' => ChatProvider::WHATSAPP,
                    'name' => $target->displayName(),
                    'target' => $target,
                    'picture' => null,
                    'icon' => $summary['icon'] ?? null,
                    'preview' => $summary === null ? null : ($summary['text'] ?? $summary['label']),
                    'fromMe' => $last !== null && $last->from_me,
                    'sender' => $last?->senderLabel(),
                    'at' => CarbonImmutable::instance($target->last_message_at),
                    'unread' => $target->unread_count,
                    'urgency' => $target->aiUrgencySlug(),
                    'url' => $inbox && $target->conversation !== null
                        ? route('chats.chat', ['conversation' => $target->conversation->friendly_id])
                        : route('whatsapp.chat', ['account' => $target->account->friendly_id, 'chat' => $target->friendly_id]),
                ];
            }
        }

        if ($this->user()->hasModule(Module::CHATS)) {
            $conversations = $this->unreadChatsQuery()
                ->with('provider')
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->limit(self::MESSAGES_SHOWN)
                ->get();

            foreach ($conversations as $conversation) {
                $last = $conversation->last_message;

                $rows[] = [
                    'key' => $conversation->friendly_id,
                    'provider' => $conversation->provider->slug,
                    'name' => $conversation->displayName(),
                    'target' => null,
                    'picture' => $conversation->picture_url,
                    'icon' => ($last['kind'] ?? 'text') === 'file' ? 'paper-clip' : null,
                    'preview' => $last['text'] ?? null,
                    'fromMe' => $last !== null && $last['from_me'],
                    'sender' => $conversation->isDirect() ? null : $last['sender'] ?? null,
                    'at' => CarbonImmutable::instance($conversation->last_message_at),
                    'unread' => $conversation->unread_count,
                    'urgency' => null,
                    'url' => route('chats.chat', ['conversation' => $conversation->friendly_id]),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($rows, 0, self::MESSAGES_SHOWN);
    }

    #[Computed]
    public function unreadWhatsappCount(): int
    {
        return $this->user()->hasModule(Module::WHATSAPP) ? $this->unreadWhatsappQuery()->count() : 0;
    }

    #[Computed]
    public function unreadChatsCount(): int
    {
        return $this->user()->hasModule(Module::CHATS) ? $this->unreadChatsQuery()->count() : 0;
    }

    /**
     * The open shopping lists with items still to pick, the next due first,
     * with their counts.
     *
     * @return Collection<int, ShoppingList>
     */
    #[Computed]
    public function shoppingLists(): Collection
    {
        if (! $this->user()->hasModule(Module::SHOPPING)) {
            return new Collection;
        }

        return ShoppingList::query()
            ->visibleTo($this->user())
            ->notArchived()
            ->whereHas('items', fn (Builder $query) => $query->whereNull('checked_at'))
            ->withItemCounts()
            ->orderByRaw('due_at asc nulls last')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::SHOPPING_LISTS_SHOWN)
            ->get();
    }

    /**
     * How many items are still to pick in the open lists.
     */
    #[Computed]
    public function openShoppingItemsCount(): int
    {
        if (! $this->user()->hasModule(Module::SHOPPING)) {
            return 0;
        }

        return ShoppingListItem::query()
            ->whereNull('checked_at')
            ->whereHas('list', fn (Builder $query) => $query->visibleTo($this->user())->notArchived())
            ->count();
    }

    /**
     * How many invoices wait for a review or failed to go out.
     */
    #[Computed]
    public function invoicesAwaitingCount(): int
    {
        if (! $this->user()->hasModule(Module::INVOICES)) {
            return 0;
        }

        return Invoice::query()
            ->visibleTo($this->user())
            ->whereIn('invoice_status_id', [InvoiceStatus::idFor(InvoiceStatus::PENDING_REVIEW), InvoiceStatus::idFor(InvoiceStatus::FAILED)])
            ->count();
    }

    /**
     * Where "Open chats" goes: the inbox of every channel with the chats
     * module, else the WhatsApp screen.
     */
    public function chatsUrl(): string
    {
        return $this->user()->hasModule(Module::CHATS) ? route('chats.chat') : route('whatsapp.chat');
    }

    public function render(): View
    {
        return view('livewire.dashboard')->title(__('Home'));
    }

    /**
     * The unread chats of the user's WhatsApp numbers, archived ones left
     * out.
     *
     * @return Builder<WhatsappTarget>
     */
    private function unreadWhatsappQuery(): Builder
    {
        return WhatsappTarget::query()
            ->whereIn('whatsapp_account_id', $this->user()->whatsappAccounts()->select('id'))
            ->whereNotNull('last_message_at')
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query->where('unread_count', '>', 0)->orWhere('marked_unread', true));
    }

    /**
     * The unread conversations of the chat channels the user may use other
     * than WhatsApp — whose chats come from unreadWhatsappQuery() — archived
     * ones left out.
     *
     * @return Builder<ChatConversation>
     */
    private function unreadChatsQuery(): Builder
    {
        $providerIds = app(ChatProviderRegistry::class)->providersFor($this->user())
            ->reject(fn (ChatProvider $provider): bool => $provider->slug === ChatProvider::WHATSAPP)
            ->modelKeys();

        return ChatConversation::query()
            ->ofUser($this->user())
            ->whereIn('chat_provider_id', $providerIds)
            ->whereNotNull('last_message_at')
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query->where('unread_count', '>', 0)->orWhere('marked_unread', true));
    }

    /**
     * The open reminders past due the user sees, as the agenda's "Overdue"
     * filter has them (events and deleted reminders left out).
     *
     * @return Builder<ReminderOccurrence>
     */
    private function overdueQuery(): Builder
    {
        return app(ReminderOccurrenceFeed::class)->query($this->user(), ReminderOccurrenceFeed::OVERDUE);
    }

    /**
     * Now in the user's timezone.
     */
    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->user()->timezone);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
