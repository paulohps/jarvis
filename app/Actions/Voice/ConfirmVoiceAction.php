<?php

namespace App\Actions\Voice;

use App\Actions\Whatsapp\SendWhatsappNow;
use App\Models\User;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceConfirmations;
use App\Services\Voice\VoiceResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The user confirms a voice WhatsApp send (US-10.3, US-7.4): the send kept
 * under the token by ExecuteVoiceAction goes out through SendWhatsappNow,
 * as one `voice` delivery. A token works once and for 5 minutes, only for
 * the user it was issued to; otherwise a 422 and nothing is sent. The
 * target must still be one of the user's own.
 */
final readonly class ConfirmVoiceAction
{
    public function __construct(
        private VoiceConfirmations $confirmations,
        private SendWhatsappNow $sendWhatsappNow,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, mixed $token): VoiceResult
    {
        $pending = is_string($token) && $token !== '' ? $this->confirmations->claim($actor, $token) : null;

        $target = $pending === null ? null : WhatsappTarget::query()
            ->whereKey($pending['target_id'])
            ->whereIn('whatsapp_account_id', $actor->whatsappAccounts()->select('id'))
            ->with('account')
            ->first();

        if ($pending === null || $target === null) {
            throw ValidationException::withMessages([
                'confirmation_token' => __('This confirmation expired or was already used. Say the command again.'),
            ]);
        }

        $action = VoiceAction::from(VoiceAction::WHATSAPP_SEND, [
            'target' => $target->friendly_id,
            'body' => $pending['body'],
            'account' => $target->account->friendly_id,
        ]);

        try {
            ($this->sendWhatsappNow)($actor, [
                'account' => $target->account->friendly_id,
                'targets' => [$target->friendly_id],
                'body' => $pending['body'],
            ], WhatsappDeliverySource::VOICE);
        } catch (ValidationException $exception) {
            return VoiceResult::failed($action, (string) Arr::first(Arr::flatten($exception->errors())));
        }

        return VoiceResult::done($action, __('Sending ":body" to :target.', [
            'body' => $pending['body'],
            'target' => $target->displayName(),
        ]));
    }
}
