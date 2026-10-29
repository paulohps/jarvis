<?php

namespace App\Livewire\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Events\NotificationCreated;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use App\Services\Notifications\NotificationItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The notification bell of the top bar (US-5.1, ported from my-finance).
 *
 * Nothing polls: the unread count is read on each page and refreshed by the
 * `NotificationCreated` broadcast on the user's private channel (Echo); the
 * latest 10 notifications are only read once the bell is opened. Each one
 * opens through `notifications.open`, which marks it read and follows its
 * action.
 *
 * @property-read string|null $badge
 * @property-read Collection<int, NotificationItem> $items
 */
class Bell extends Component
{
    public const int PREVIEW_LIMIT = 10;

    public const int MAX_DISPLAYED_COUNT = 99;

    #[Locked]
    public int $userId = 0;

    public int $unreadCount = 0;

    public bool $isLoaded = false;

    public function mount(NotificationFeed $feed): void
    {
        $this->userId = $this->user()->id;
        $this->unreadCount = $feed->unreadCount($this->user());
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.NotificationCreated::channelFor($this->userId).',.notification.created' => 'refreshNotifications',
            // A push the service worker of the PWA received (resources/js/components/pwa.js).
            'pwa:push-received' => 'refreshNotifications',
        ];
    }

    /**
     * The unread count as the badge shows it: `99+` above 99, nothing at 0.
     */
    #[Computed]
    public function badge(): ?string
    {
        if ($this->unreadCount < 1) {
            return null;
        }

        return $this->unreadCount > self::MAX_DISPLAYED_COUNT
            ? self::MAX_DISPLAYED_COUNT.'+'
            : (string) $this->unreadCount;
    }

    /**
     * @return Collection<int, NotificationItem>
     */
    #[Computed]
    public function items(): Collection
    {
        if (! $this->isLoaded) {
            return new Collection;
        }

        return app(NotificationFeed::class)->latest($this->user(), self::PREVIEW_LIMIT);
    }

    public function loadItems(NotificationFeed $feed): void
    {
        $this->isLoaded = true;
        $this->refreshNotifications($feed);
    }

    public function refreshNotifications(NotificationFeed $feed): void
    {
        $this->unreadCount = $feed->unreadCount($this->user());
        unset($this->items);
    }

    public function markAllAsRead(MarkNotificationsRead $markRead, NotificationFeed $feed): void
    {
        $markRead($this->user());

        $this->refreshNotifications($feed);
    }

    public function render(): View
    {
        return view('livewire.notifications.bell');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
