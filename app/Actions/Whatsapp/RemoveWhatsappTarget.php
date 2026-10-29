<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes a contact or group added by hand. Refused for a synced one (the
 * next sync brings it back) and for one a message goes to or that was
 * already sent to, since removing it would take those recipients and that
 * history with it.
 *
 * Only the account's owner may do it, with the `whatsapp` module.
 */
final readonly class RemoveWhatsappTarget
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, WhatsappTarget $target): void
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $target->account);

        if ($target->is_synced) {
            throw ValidationException::withMessages(['target' => __('Synced contacts and groups cannot be removed; they come back with the next sync.')]);
        }

        if ($target->messages()->exists() || WhatsappDelivery::query()->where('whatsapp_target_id', $target->id)->exists()) {
            throw ValidationException::withMessages(['target' => __(':name is used by a message or its history, so it stays.', ['name' => $target->displayName()])]);
        }

        $target->delete();
    }
}
