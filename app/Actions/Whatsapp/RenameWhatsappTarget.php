<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Renames a contact or group added by hand (an empty name shows the number
 * or id). A synced one keeps the name WhatsApp gives it, since the next
 * sync would bring it back anyway.
 *
 * Only the account's owner may do it, with the `whatsapp` module.
 */
final readonly class RenameWhatsappTarget
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, WhatsappTarget $target, ?string $name): WhatsappTarget
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $target->account);

        if ($target->is_synced) {
            throw ValidationException::withMessages(['name' => __('Synced contacts and groups keep the name they have on WhatsApp.')]);
        }

        /** @var array{name: string|null} $validated */
        $validated = Validator::make(
            ['name' => filled($name) ? mb_trim((string) $name) : null],
            ['name' => ['nullable', 'string', 'max:255']],
        )->validate();

        $target->update(['name' => $validated['name']]);

        return $target;
    }
}
