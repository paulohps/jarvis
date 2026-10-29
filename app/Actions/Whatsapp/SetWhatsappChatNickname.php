<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The user's own nickname for a contact or group, shown everywhere in place
 * of the name WhatsApp gives it (WhatsappTarget::displayName()), which syncs
 * keep updating underneath. A blank nickname goes back to that name.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SetWhatsappChatNickname
{
    use AuthorizesWhatsappModule;

    public const int MAX_LENGTH = 100;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, ?string $nickname): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        /** @var array{nickname: string|null} $validated */
        $validated = Validator::make(
            ['nickname' => filled($nickname) ? mb_trim((string) $nickname) : null],
            ['nickname' => ['nullable', 'string', 'max:'.self::MAX_LENGTH]],
            attributes: ['nickname' => __('nickname')],
        )->validate();

        $chat->update(['nickname' => $validated['nickname']]);

        return $chat;
    }
}
