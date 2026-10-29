<?php

namespace App\Actions\Chats;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Silences a conversation for 8 hours, a week or always, or unmutes it
 * (`null`): while muted, what it gets is not pushed to the owner's
 * devices, and the list shows it muted. Kept in Jarvis only.
 */
final readonly class MuteChatConversation
{
    use AuthorizesChats, ChangesChatConversation;

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

    public function __construct(private ChatProviderRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ChatProviderException
     * @throws ValidationException
     */
    public function __invoke(User $actor, ChatConversation $conversation, ?string $duration): ChatConversation
    {
        $this->authorizeConversation($actor, $conversation);

        Validator::make(['duration' => $duration], ['duration' => ['nullable', Rule::in(array_keys(self::DURATIONS))]])->validate();

        $this->change($this->registry, $conversation, ['muted_until' => match ($duration) {
            '8h' => now()->addHours(8),
            '1w' => now()->addWeek(),
            'always' => Carbon::parse(ChatConversation::MUTED_FOREVER),
            default => null,
        }]);

        return $conversation;
    }
}
