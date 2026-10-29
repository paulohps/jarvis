<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens an in-app notification from the bell or the history (US-5.1): marks
 * it read and follows its action (`?action=<index>`, the first by default),
 * or goes to the history when it has none. Someone else's notification is
 * not found (404).
 */
class OpenNotificationController extends Controller
{
    public function __invoke(Request $request, string $notification, NotificationFeed $feed): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $url = $feed->open($user, $notification, max(0, $request->integer('action')));

        return redirect()->to($url ?? route('notifications.index'));
    }
}
