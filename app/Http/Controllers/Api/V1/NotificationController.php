<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\MarkNotificationsRead;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The in-app notifications of the user (US-5.1), the same feed as the bell
 * and the history page: newest first, only the user's own.
 */
class NotificationController extends Controller
{
    public const int PER_PAGE = 20;

    public const int MAX_PER_PAGE = 50;

    /**
     * `GET /api/v1/notifications`: paginated (`page`, `per_page` up to 50),
     * `unread=1` for the unread ones only; `meta.unread_count` feeds the badge.
     */
    public function index(Request $request, NotificationFeed $feed): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $perPage = min(max($request->integer('per_page', self::PER_PAGE), 1), self::MAX_PER_PAGE);

        return NotificationResource::collection($feed->paginate($user, $request->boolean('unread'), $perPage))
            ->additional(['meta' => ['unread_count' => $feed->unreadCount($user)]]);
    }

    /**
     * `POST /api/v1/notifications/{id}/read`: someone else's is a 404.
     */
    public function read(Request $request, string $notification, MarkNotificationsRead $markRead, NotificationFeed $feed): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->marked($user, $markRead($user, $notification), $feed);
    }

    /**
     * `POST /api/v1/notifications/read-all`.
     */
    public function readAll(Request $request, MarkNotificationsRead $markRead, NotificationFeed $feed): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->marked($user, $markRead($user), $feed);
    }

    private function marked(User $user, int $marked, NotificationFeed $feed): JsonResponse
    {
        return response()->json(['marked' => $marked, 'unread_count' => $feed->unreadCount($user)]);
    }
}
