<?php

namespace App\Http\Controllers\Push;

use App\Actions\Devices\RegisterWebDevice;
use App\Actions\Devices\RevokeDevice;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The browser's push registration of the web app (US-5.2, on the web),
 * called by the page's script with the session: it stores the FCM token
 * the browser got as a `web` device, and forgets that device when the user
 * turns push off in the browser.
 */
class WebPushSubscriptionController extends Controller
{
    /**
     * `POST /push/subscriptions`: 201 for a new device, 200 for an updated one.
     */
    public function store(Request $request, RegisterWebDevice $registerWebDevice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $device = $registerWebDevice($user, $request->only(['name', 'push_token', 'device']));

        return response()->json([
            'data' => [
                'friendly_id' => $device->friendly_id,
                'name' => $device->name,
            ],
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * `DELETE /push/subscriptions/{device:friendly_id}`.
     */
    public function destroy(Request $request, Device $device, RevokeDevice $revokeDevice): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revokeDevice($user, $device);

        return response()->noContent();
    }
}
