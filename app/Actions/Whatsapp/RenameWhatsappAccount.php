<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Gives an account a new nickname, the name it goes by on every screen.
 * Only the owner may do it, with the `whatsapp` module.
 */
final readonly class RenameWhatsappAccount
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, WhatsappAccount $account, string $nickname): WhatsappAccount
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        /** @var array{nickname: string} $validated */
        $validated = Validator::make(
            ['nickname' => mb_trim($nickname)],
            ['nickname' => ['required', 'string', 'max:100']],
        )->validate();

        $account->update(['nickname' => $validated['nickname']]);

        return $account;
    }
}
