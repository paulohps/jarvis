<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    use ResolvesLookups;

    /**
     * An iPhone without a push token yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_platform_id' => fn (): int => static::lookupId(DevicePlatform::class, DevicePlatform::IOS, ['name' => 'iOS']),
            'name' => 'iPhone',
            'push_token' => null,
        ];
    }

    public function ios(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_platform_id' => static::lookupId(DevicePlatform::class, DevicePlatform::IOS, ['name' => 'iOS']),
            'name' => 'iPhone',
        ]);
    }

    public function android(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_platform_id' => static::lookupId(DevicePlatform::class, DevicePlatform::ANDROID, ['name' => 'Android']),
            'name' => 'Pixel',
        ]);
    }

    /**
     * A browser the web app registered for push in (no API token).
     */
    public function web(): static
    {
        return $this->state(fn (array $attributes) => [
            'device_platform_id' => static::lookupId(DevicePlatform::class, DevicePlatform::WEB, ['name' => 'Browser']),
            'name' => 'Safari on iPhone',
            'personal_access_token_id' => null,
        ]);
    }

    /**
     * Registered for push with an FCM token.
     */
    public function withPushToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'push_token' => Str::random(32).':'.Str::random(140),
        ]);
    }
}
