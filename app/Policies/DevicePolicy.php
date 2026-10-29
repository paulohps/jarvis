<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

/**
 * A device is only seen and revoked by its own user (US-3.4).
 */
class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        return $device->user_id === $user->id;
    }

    public function delete(User $user, Device $device): bool
    {
        return $device->user_id === $user->id;
    }
}
