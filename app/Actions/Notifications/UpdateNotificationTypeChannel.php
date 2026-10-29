<?php

namespace App\Actions\Notifications;

use App\Models\MailService;
use App\Models\NotificationChannel;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin switches a channel on or off (US-5.5, ported from
 * my-finance), at one of two levels:
 *
 * - on the platform (`NotificationChannel`): a channel switched off delivers
 *   no type and leaves the preferences of every user. The WhatsApp channel
 *   also names the account the platform sends from (`whatsapp_account`, the
 *   friendly id of an account of a super admin; empty for none), which users
 *   who did not pick one of their own get their notifications from;
 * - on a type (`NotificationTypeChannel`): the channel stops delivering that
 *   type, and `is_enabled_by_default` decides what users who never chose
 *   get. Whoever already chose in the profile keeps their choice. The
 *   e-mail channel of a type may also go out through a global mail service
 *   or one of a super admin (`mail_service`, its friendly id; empty for the
 *   default mailer).
 *
 * Saving through the model drops the cached settings
 * (`NotificationSettingsObserver`), so the next dispatch already obeys.
 */
final readonly class UpdateNotificationTypeChannel
{
    /**
     * @param  array{is_active?: mixed, is_enabled_by_default?: mixed, mail_service?: mixed, whatsapp_account?: mixed}  $input  only `is_active` (and `whatsapp_account` for WhatsApp) for a platform channel
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, NotificationTypeChannel|NotificationChannel $channel, array $input): NotificationTypeChannel|NotificationChannel
    {
        Gate::forUser($actor)->authorize('super-admin');

        $rules = ['is_active' => ['sometimes', 'boolean']];

        if ($channel instanceof NotificationTypeChannel) {
            $rules['is_enabled_by_default'] = ['sometimes', 'boolean'];

            if ($channel->notification_channel_id === NotificationChannel::idFor(NotificationChannel::MAIL)) {
                $rules['mail_service'] = ['sometimes', 'nullable', 'string', Rule::in(MailService::query()->forNotifications()->pluck('friendly_id')->all())];
            }
        } elseif ($channel->slug === NotificationChannel::WHATSAPP) {
            $rules['whatsapp_account'] = ['sometimes', 'nullable', 'string', Rule::in(WhatsappAccount::query()->forNotifications()->pluck('friendly_id')->all())];
        }

        /** @var array{is_active?: bool, is_enabled_by_default?: bool, mail_service?: string|null, whatsapp_account?: string|null} $validated */
        $validated = Validator::make($input, $rules)->validate();

        if (array_key_exists('mail_service', $validated)) {
            $validated['mail_service_id'] = filled($validated['mail_service'])
                ? MailService::query()->where('friendly_id', $validated['mail_service'])->value('id')
                : null;
            unset($validated['mail_service']);
        }

        if (array_key_exists('whatsapp_account', $validated)) {
            $validated['whatsapp_account_id'] = filled($validated['whatsapp_account'])
                ? WhatsappAccount::query()->where('friendly_id', $validated['whatsapp_account'])->value('id')
                : null;
            unset($validated['whatsapp_account']);
        }

        $channel->update($validated);

        return $channel;
    }
}
