<?php

namespace App\Actions\Notifications;

use App\Actions\Whatsapp\SendWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Notifications\Channels\WhatsappChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sends a 6-digit code to the number the user's WhatsApp notifications go
 * to, from the platform's account, so they prove the number is theirs
 * (`VerifyWhatsappNotificationPhone`) before the platform sends anything
 * else to it.
 *
 * The code is kept hashed in the cache for `EXPIRES_IN_MINUTES`; a new code
 * replaces the previous one. Codes are throttled per user and per number,
 * so the verification itself cannot be used to flood a number.
 */
final readonly class SendWhatsappNotificationCode
{
    public const int EXPIRES_IN_MINUTES = 10;

    public const int MAX_CODES_PER_USER = 3;

    public const int USER_DECAY_SECONDS = 600;

    public const int MAX_CODES_PER_NUMBER = 5;

    public const int NUMBER_DECAY_SECONDS = 86400;

    public function __construct(private SendWhatsAppMessage $send) {}

    /**
     * @throws ValidationException when there is nothing to verify, nothing to send it from, or too many codes were sent
     */
    public function __invoke(User $user): void
    {
        $phone = (string) $user->whatsapp_notification_phone;
        $account = WhatsappChannel::platformAccount();

        if ($phone === '' || $user->hasVerifiedWhatsappNotificationPhone()) {
            throw ValidationException::withMessages(['code' => __('There is no number to verify.')]);
        }

        if ($account === null) {
            throw ValidationException::withMessages(['code' => __('WhatsApp notifications cannot be sent right now.')]);
        }

        $userKey = 'whatsapp-notification-code:user:'.$user->id;
        $numberKey = 'whatsapp-notification-code:number:'.$phone;

        foreach ([$userKey => self::MAX_CODES_PER_USER, $numberKey => self::MAX_CODES_PER_NUMBER] as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages(['code' => __('Too many codes sent. Try again in :minutes minutes.', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ])]);
            }
        }

        RateLimiter::hit($userKey, self::USER_DECAY_SECONDS);
        RateLimiter::hit($numberKey, self::NUMBER_DECAY_SECONDS);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $expiresAt = now()->addMinutes(self::EXPIRES_IN_MINUTES);

        Cache::put(self::cacheKey($user), [
            'phone' => $phone,
            'hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        ($this->send)(
            $account,
            WhatsappTarget::forNumber($account, $phone),
            __(':app verification code: :code. It expires in :minutes minutes. If you did not ask for it, ignore this message.', [
                'app' => config('app.name'),
                'code' => $code,
                'minutes' => self::EXPIRES_IN_MINUTES,
            ], $user->preferredLocale()),
            WhatsappDeliverySource::NOTIFICATION,
            triggeredBy: $user,
        );
    }

    /**
     * Where the pending code of the user is kept.
     */
    public static function cacheKey(User $user): string
    {
        return 'whatsapp-notification-code:pending:'.$user->id;
    }
}
