<?php

namespace App\Services\Notifications\Channels;

use App\Exceptions\FcmException;
use App\Models\Device;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Push\FcmClient;
use Illuminate\Notifications\Notification;

/**
 * The push channel of the notification center (US-5.2, US-5.3): the
 * notification as plain text, with the URL of its first action as the
 * `deep_link`, sent through FCM to every device of the user with a push
 * token.
 *
 * Each device is on its own: one that got the push is marked used
 * (`last_used_at`, shown in the profile), a token FCM rejects
 * (`UNREGISTERED`, `INVALID_ARGUMENT` for the token) is removed from its
 * device, and any other failure is reported, never keeping the next devices
 * from getting theirs. Only when no device got it and one may still do (a failure
 * other than a rejected token) does the job fail, to be retried; without
 * an access token nothing is sent and the job fails too.
 */
final readonly class PushChannel
{
    public function __construct(private FcmClient $fcm) {}

    /**
     * Whether a push can reach the user: FCM is configured and at least one
     * of their devices has a push token.
     */
    public static function canReach(User $user): bool
    {
        return app(FcmClient::class)->isConfigured()
            && $user->devices()->whereNotNull('push_token')->exists();
    }

    /**
     * @return int how many devices got the push
     *
     * @throws FcmException
     */
    public function send(object $notifiable, Notification $notification): int
    {
        if (! $notifiable instanceof User || ! $notification instanceof CenterNotification) {
            return 0;
        }

        $devices = $notifiable->devices()->whereNotNull('push_token')->orderBy('id')->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $message = $notification->toPush($notifiable);

        // Authenticate once, before any device: a failure here fails the job.
        $this->fcm->accessToken();

        $sent = 0;
        $lastFailure = null;

        foreach ($devices as $device) {
            try {
                $this->fcm->send((string) $device->push_token, $message);
                $this->markUsed($device);
                $sent++;
            } catch (FcmException $exception) {
                if ($exception->isTokenRejected()) {
                    $this->forgetToken($device);

                    continue;
                }

                report($exception);
                $lastFailure = $exception;
            }
        }

        if ($sent === 0 && $lastFailure !== null) {
            throw $lastFailure;
        }

        return $sent;
    }

    private function markUsed(Device $device): void
    {
        $device->forceFill(['last_used_at' => now()])->save();
    }

    private function forgetToken(Device $device): void
    {
        $device->forceFill(['push_token' => null])->save();
    }
}
