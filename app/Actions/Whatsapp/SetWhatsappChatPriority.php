<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Sets the priority of a chat on the WhatsApp screen: from
 * WhatsappTarget::HIGHEST_PRIORITY (1) to LOWEST_PRIORITY (5), shown in
 * the color the user set for that priority (SetWhatsappPriorityColors). A
 * null priority clears it.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SetWhatsappChatPriority
{
    use AuthorizesWhatsappModule;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, ?int $priority): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        /** @var array{priority: int|null} $validated */
        $validated = Validator::make(
            ['priority' => $priority],
            ['priority' => ['nullable', 'integer', 'between:'.WhatsappTarget::HIGHEST_PRIORITY.','.WhatsappTarget::LOWEST_PRIORITY]],
            attributes: ['priority' => __('priority')],
        )->validate();

        $chat->update(['priority' => $validated['priority']]);

        return $chat;
    }
}
