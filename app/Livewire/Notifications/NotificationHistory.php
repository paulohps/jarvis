<?php

namespace App\Livewire\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use App\Services\Notifications\NotificationItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every in-app notification of the user, newest first (US-5.1, ported from
 * my-finance): all or only the unread ones (kept in the URL), 20 per page,
 * each with its date in the user's timezone and its actions, which open
 * through `notifications.open`.
 *
 * @property-read LengthAwarePaginator<int, NotificationItem> $notifications
 * @property-read int $unreadCount
 */
#[Layout('layouts::app-shell')]
class NotificationHistory extends Component
{
    use WithPagination;

    public const int PER_PAGE = 20;

    public const string ALL = 'all';

    public const string UNREAD = 'unread';

    #[Url(except: self::ALL)]
    public string $filter = self::ALL;

    public function mount(): void
    {
        $this->filter = $this->normalizedFilter();
    }

    public function updatedFilter(): void
    {
        $this->filter = $this->normalizedFilter();
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, NotificationItem>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return app(NotificationFeed::class)->paginate($this->user(), $this->filter === self::UNREAD, self::PER_PAGE);
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(NotificationFeed::class)->unreadCount($this->user());
    }

    public function markAsRead(string $notification, MarkNotificationsRead $markRead): void
    {
        $markRead($this->user(), $notification);

        $this->refresh();
    }

    public function markAllAsRead(MarkNotificationsRead $markRead): void
    {
        $markRead($this->user());

        $this->refresh();
    }

    public function render(): View
    {
        return view('livewire.notifications.notification-history')->title(__('Notifications'));
    }

    private function refresh(): void
    {
        unset($this->notifications, $this->unreadCount);
    }

    private function normalizedFilter(): string
    {
        return $this->filter === self::UNREAD ? self::UNREAD : self::ALL;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
