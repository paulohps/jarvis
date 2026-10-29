<?php

namespace App\Services\Notifications;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Models\Notification;
use App\Models\User;
use App\Services\Templates\HtmlSanitizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * The in-app notifications of a user (US-5.1), read by the bell and the
 * history page, newest first, only ever through the user's own relation.
 *
 * Actions are shown only while they point inside the application, so a
 * stored URL can never send the reader elsewhere.
 */
final readonly class NotificationFeed
{
    public function __construct(
        private HtmlSanitizer $sanitizer,
        private MarkNotificationsRead $markRead,
    ) {}

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, NotificationItem>
     */
    public function latest(User $user, int $limit): Collection
    {
        return $this->present($user->notifications()->limit($limit)->get());
    }

    /**
     * @return LengthAwarePaginator<int, NotificationItem>
     */
    public function paginate(User $user, bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        $page = $user->notifications()
            ->when($unreadOnly, fn (Builder $query) => $query->whereNull('read_at'))
            ->paginate($perPage);

        $page->setCollection($this->present($page->getCollection()));

        return $page;
    }

    /**
     * Marks the notification read and returns where its action leads (the
     * first one by default), or null when there is nowhere to go.
     *
     * @throws ModelNotFoundException<Notification> when the notification is not the user's
     */
    public function open(User $user, string $id, int $action = 0): ?string
    {
        ($this->markRead)($user, $id);

        /** @var Notification $notification */
        $notification = $user->notifications()->whereKey($id)->firstOrFail();

        return $this->present(new Collection([$notification]))->sole()->actions[$action]['url'] ?? null;
    }

    /**
     * @param  iterable<int, Notification>  $notifications
     * @return Collection<int, NotificationItem>
     */
    private function present(iterable $notifications): Collection
    {
        return (new Collection($notifications))
            ->map(function (Notification $notification): NotificationItem {
                $data = $notification->data;

                return new NotificationItem(
                    id: $notification->id,
                    title: (string) ($data['title'] ?? ''),
                    body: $this->sanitizer->sanitize((string) ($data['body'] ?? '')),
                    actions: $this->actions($data['actions'] ?? []),
                    isRead: $notification->read_at !== null,
                    createdAt: $notification->created_at ?? now(),
                );
            })
            ->values();
    }

    /**
     * The stored actions that still point inside the application.
     *
     * @return list<array{label: string, url: string}>
     */
    private function actions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        $valid = [];

        foreach ($actions as $action) {
            if (is_array($action) && is_string($action['label'] ?? null) && $this->isInternal($action['url'] ?? null)) {
                $valid[] = ['label' => $action['label'], 'url' => $action['url']];
            }
        }

        return $valid;
    }

    private function isInternal(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $root = rtrim(url('/'), '/');

        return $url === $root || str_starts_with($url, $root.'/');
    }
}
