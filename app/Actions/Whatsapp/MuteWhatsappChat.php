<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Silences a chat for 8 hours, a week or always, as WhatsApp offers, or
 * unmutes it (`null`): while muted, what it gets is not pushed to the
 * owner's apps (WhatsappTarget::notifiesMessages()), and the list shows it
 * muted. Kept in Jarvis: the phone keeps its own mute.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class MuteWhatsappChat
{
    use AuthorizesWhatsappModule;

    /**
     * The mutes on offer, and how each is labelled.
     *
     * @var array<string, string>
     */
    public const array DURATIONS = [
        '8h' => 'Mute for 8 hours',
        '1w' => 'Mute for 1 week',
        'always' => 'Mute always',
    ];

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappTarget $chat, ?string $duration): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        Validator::make(['duration' => $duration], ['duration' => ['nullable', Rule::in(array_keys(self::DURATIONS))]])->validate();

        $chat->update(['muted_until' => match ($duration) {
            '8h' => now()->addHours(8),
            '1w' => now()->addWeek(),
            'always' => Carbon::parse(WhatsappTarget::MUTED_FOREVER),
            default => null,
        }]);

        return $chat;
    }
}
