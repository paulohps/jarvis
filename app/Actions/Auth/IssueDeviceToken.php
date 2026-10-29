<?php

namespace App\Actions\Auth;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

/**
 * Gives the app a Sanctum token of its own (US-1.5), named after the device
 * it signed in from, so each device can be signed out or revoked alone.
 */
final readonly class IssueDeviceToken
{
    public function __invoke(User $user, string $deviceName): NewAccessToken
    {
        return $user->createToken($deviceName);
    }

    /**
     * The validation rule of the device name sent at sign-in.
     *
     * @return array<string, list<string>>
     */
    public static function deviceNameRules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
