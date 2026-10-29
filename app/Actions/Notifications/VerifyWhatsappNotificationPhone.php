<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Checks the code `SendWhatsappNotificationCode` sent to the user's
 * notification number and, when it matches, marks the number verified, so
 * the platform's account starts sending notifications to it.
 *
 * The code only verifies the number it was sent to, and stops working after
 * `MAX_ATTEMPTS` wrong tries or when it expires.
 */
final readonly class VerifyWhatsappNotificationPhone
{
    public const int MAX_ATTEMPTS = 5;

    public function __construct(private NotificationGate $gate) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, mixed $code): User
    {
        $key = SendWhatsappNotificationCode::cacheKey($user);

        /** @var array{phone: string, hash: string, attempts: int, expires_at: int}|null $pending */
        $pending = Cache::get($key);
        $code = is_string($code) ? (string) preg_replace('/\D/', '', $code) : '';

        if ($pending === null || $pending['phone'] !== $user->whatsapp_notification_phone) {
            throw ValidationException::withMessages(['code' => __('The code expired. Ask for a new one.')]);
        }

        if (! Hash::check($code, $pending['hash'])) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($key);

                throw ValidationException::withMessages(['code' => __('Too many wrong codes. Ask for a new one.')]);
            }

            Cache::put($key, $pending, Carbon::createFromTimestamp($pending['expires_at']));

            throw ValidationException::withMessages(['code' => __('The code is not right.')]);
        }

        Cache::forget($key);

        $user->forceFill(['whatsapp_notification_phone_verified_at' => now()])->save();
        $this->gate->forget($user);

        return $user;
    }
}
