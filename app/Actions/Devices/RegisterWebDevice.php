<?php

namespace App\Actions\Devices;

use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The web app registers the browser it runs in for push (US-5.2, on the
 * web): a device of the `web` platform with no API token, named after the
 * browser and the device ("Safari on iPhone"), holding the FCM registration
 * token the browser got.
 *
 * The browser sends back the `device` it registered before (its friendly
 * id, kept in its storage), so a refreshed token updates that device instead
 * of adding one; a browser that lost it, or whose device was revoked, gets
 * a new one. As on the phones, a `push_token` is unique: another device
 * holding it (another user signed in on the same browser) loses it.
 */
final readonly class RegisterWebDevice
{
    /**
     * @param  array{name?: mixed, push_token?: mixed, device?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): Device
    {
        /** @var array{name: string, push_token: string, device?: string|null} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'push_token' => ['required', 'string', 'max:512'],
            'device' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        return DB::transaction(function () use ($user, $validated): Device {
            $device = $this->existing($user, $validated['device'] ?? null, $validated['push_token']);

            Device::query()
                ->where('push_token', $validated['push_token'])
                ->when($device !== null, fn (Builder $query) => $query->whereKeyNot($device->id))
                ->update(['push_token' => null]);

            $attributes = [
                'user_id' => $user->id,
                'personal_access_token_id' => null,
                'device_platform_id' => DevicePlatform::idFor(DevicePlatform::WEB),
                'name' => $validated['name'],
                'push_token' => $validated['push_token'],
                'last_used_at' => now(),
            ];

            if ($device === null) {
                return Device::query()->create($attributes);
            }

            $device->forceFill($attributes)->save();

            return $device;
        });
    }

    /**
     * The user's web device the browser registered before: the one it
     * names, or the one already holding this token.
     */
    private function existing(User $user, ?string $friendlyId, string $pushToken): ?Device
    {
        $webDevices = $user->devices()
            ->where('device_platform_id', DevicePlatform::idFor(DevicePlatform::WEB));

        if ($friendlyId !== null) {
            $named = (clone $webDevices)->where('friendly_id', $friendlyId)->first();

            if ($named !== null) {
                return $named;
            }
        }

        return $webDevices->where('push_token', $pushToken)->first();
    }
}
