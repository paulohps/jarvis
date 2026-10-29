<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The app registers the device it is signed in on (US-3.4, US-5.2): one
 * device per Sanctum token, created on the first call and updated on the
 * next ones (platform, name, FCM `push_token`, last use).
 *
 * A `push_token` is unique: when another record already holds it (the app
 * signed in again, or another user signed in on the same phone), it moves to
 * this device, so a phone never gets the push of an earlier sign-in. Leaving
 * `push_token` out keeps the stored one; sending null removes it.
 */
final readonly class RegisterDevice
{
    /**
     * @param  array{platform?: mixed, name?: mixed, push_token?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, PersonalAccessToken $token, array $input): Device
    {
        /** @var array{platform: string, name: string, push_token?: string|null} $validated */
        $validated = Validator::make($input, [
            'platform' => ['required', 'string', Rule::in([DevicePlatform::IOS, DevicePlatform::ANDROID])],
            'name' => ['required', 'string', 'max:255'],
            'push_token' => ['sometimes', 'nullable', 'string', 'max:512'],
        ])->validate();

        $attributes = [
            'user_id' => $user->id,
            'device_platform_id' => DevicePlatform::idFor($validated['platform']),
            'name' => $validated['name'],
            'last_used_at' => now(),
        ];

        if (array_key_exists('push_token', $validated)) {
            $attributes['push_token'] = $validated['push_token'];
        }

        return DB::transaction(function () use ($token, $attributes): Device {
            if (($attributes['push_token'] ?? null) !== null) {
                Device::query()
                    ->where('push_token', $attributes['push_token'])
                    ->where(fn (Builder $query) => $query
                        ->whereNull('personal_access_token_id')
                        ->orWhere('personal_access_token_id', '!=', $token->getKey()))
                    ->update(['push_token' => null]);
            }

            return Device::query()->updateOrCreate(
                ['personal_access_token_id' => $token->getKey()],
                $attributes,
            );
        });
    }
}
