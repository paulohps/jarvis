<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use App\Models\NotificationPreference;
use App\Models\NotificationType;
use App\Models\User;
use Database\Factories\Concerns\ResolvesLookups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    use ResolvesLookups;

    /**
     * A new user opting out of `reminder_due` by push.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notification_type_id' => fn (): int => static::lookupId(NotificationType::class, NotificationType::REMINDER_DUE, ['is_active' => true]),
            'notification_channel_id' => fn (): int => static::lookupId(NotificationChannel::class, NotificationChannel::PUSH),
            'is_enabled' => false,
        ];
    }
}
