<?php

namespace App\Actions\Notifications;

use App\Actions\Whatsapp\WhatsappMessageFields;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Notifications\Channels\WhatsappChannel;
use App\Services\Notifications\NotificationGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

/**
 * Where the user's WhatsApp notifications go (US-3.3):
 *
 * - `account`: the friendly id of one of the user's own WhatsApp accounts
 *   they are sent from; empty to have them sent from the platform's account
 *   (the one the super admin picked), which is needed then;
 * - `to_account`: the friendly id of one of the user's own accounts whose
 *   number they go to; or else
 * - `phone`: the E.164 number they go to (spaces, dashes, dots and
 *   parentheses are dropped).
 *
 * They never go to the number they are sent from. The number of one of the
 * user's own accounts is verified already; any other number stays
 * unverified until the user confirms the code sent to it
 * (`SendWhatsappNotificationCode`), and the platform's account sends
 * nothing else to it until then. Sending everything empty
 * clears the settings, and the WhatsApp channel becomes unavailable again.
 *
 * The change applies from the next notification sent.
 */
final readonly class UpdateWhatsappNotificationSettings
{
    public function __construct(private NotificationGate $gate) {}

    /**
     * @param  array{account?: mixed, to_account?: mixed, phone?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function __invoke(User $user, array $input): User
    {
        $input = [
            'account' => is_string($input['account'] ?? null) ? trim($input['account']) : ($input['account'] ?? null),
            'to_account' => is_string($input['to_account'] ?? null) ? trim($input['to_account']) : ($input['to_account'] ?? null),
            'phone' => is_string($input['phone'] ?? null) ? (string) preg_replace('/[\s\-().]/', '', $input['phone']) : ($input['phone'] ?? null),
        ];
        $account = null;
        $phone = null;
        $isOwnNumber = false;

        Validator::make($input, [
            'account' => ['nullable', 'string'],
            'to_account' => ['nullable', 'string'],
            'phone' => ['nullable', Rule::requiredIf(filled($input['account']) && blank($input['to_account'])), 'string', 'regex:'.WhatsappMessageFields::E164_PATTERN],
        ], [
            'phone.regex' => __('Type the number in international format, such as +5511999990000.'),
        ], [
            'account' => __('account'),
            'phone' => __('number'),
        ])->after(function (ValidationValidator $validator) use ($user, $input, &$account, &$phone, &$isOwnNumber): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (filled($input['account'])) {
                $account = $this->ownAccount($user, $input['account']);

                if ($account === null) {
                    $validator->errors()->add('account', __('Choose one of your WhatsApp accounts.'));

                    return;
                }
            }

            if (filled($input['to_account'])) {
                $phone = $this->ownAccount($user, $input['to_account'])?->phone_number;

                if ($phone === null) {
                    $validator->errors()->add('to_account', __('Choose one of your connected WhatsApp numbers.'));

                    return;
                }
            } elseif (filled($input['phone'])) {
                $phone = $input['phone'];
            }

            if ($phone === null) {
                return;
            }

            $isOwnNumber = $user->whatsappAccounts()->where('phone_number', $phone)->exists();

            $sender = $account ?? WhatsappChannel::platformAccount();

            if ($sender === null) {
                $validator->errors()->add('account', __('Choose one of your WhatsApp accounts to send from.'));
            } elseif ($sender->phone_number === $phone) {
                $validator->errors()->add('phone', __('Choose a number other than the one the notifications are sent from.'));
            }
        })->validate();

        $user->forceFill([
            'whatsapp_notification_account_id' => $phone === null ? null : $account?->id,
            'whatsapp_notification_phone' => $phone,
            'whatsapp_notification_phone_verified_at' => match (true) {
                $phone === null => null,
                $isOwnNumber => now(),
                $phone === $user->whatsapp_notification_phone => $user->whatsapp_notification_phone_verified_at,
                default => null,
            },
        ])->save();

        $user->unsetRelation('whatsappNotificationAccount');
        $this->gate->forget($user);

        return $user;
    }

    private function ownAccount(User $user, string $friendlyId): ?WhatsappAccount
    {
        return $user->whatsappAccounts()->where('friendly_id', $friendlyId)->first();
    }
}
