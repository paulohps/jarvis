<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "New chat" on the WhatsApp screen with a number typed in international
 * format: the contact already known, or a new one (`is_synced` false, no
 * name) until WhatsApp tells its name. Spaces, dashes, dots and brackets are
 * ignored.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class StartWhatsappChat
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappAccount $account, string $number): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        $number = (string) preg_replace('/[\s\-().]/', '', $number);

        if (preg_match(WhatsappMessageFields::E164_PATTERN, $number) !== 1) {
            throw ValidationException::withMessages([
                'number' => __('Type the number in international format, such as +5511999990000.'),
            ]);
        }

        return WhatsappTarget::forNumber($account, $number);
    }
}
