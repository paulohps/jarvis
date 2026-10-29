<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The user revokes one of their devices (US-3.4), so a lost phone gets
 * nothing more: the Sanctum token the app signed in with is deleted (its
 * next call answers 401) and the device row goes with its FCM `push_token`,
 * so no push is sent to it. Their other devices and sessions are kept.
 */
final readonly class RevokeDevice
{
    /**
     * @throws AuthorizationException when the device belongs to someone else
     */
    public function __invoke(User $actor, Device $device): void
    {
        Gate::forUser($actor)->authorize('delete', $device);

        DB::transaction(function () use ($device): void {
            if ($device->personal_access_token_id !== null) {
                PersonalAccessToken::query()->whereKey($device->personal_access_token_id)->delete();
            }

            $device->delete();
        });
    }
}
