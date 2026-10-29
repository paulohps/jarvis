<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One thing done to many chats of one of the user's numbers at once, picked
 * by their friendly ids (up to MAX_CHATS), through the Actions of a single
 * chat:
 *
 * - `read`: read in Jarvis, as opening it does (what the phone is told
 *   follows the read receipts, which only an open chat sends);
 * - `unread`: marked unread, on the phone too (MarkWhatsappChatUnread);
 * - `archive` / `unarchive`: on the phone too (ArchiveWhatsappChat);
 * - `mute` / `unmute`: always, or no more (MuteWhatsappChat);
 * - `clear_priority`: the priority the user gave them goes;
 * - `clear_ai`: what the AI found about them goes (the user's priority stays).
 *
 * A chat WhatsApp refuses is counted as failed and the others go on. The
 * chats are not announced one by one: the screen that asked reloads its
 * list once.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class ApplyWhatsappChatsBulkAction
{
    use AuthorizesWhatsappModule;

    public const int MAX_CHATS = 50;

    /**
     * @var list<string>
     */
    public const array ACTIONS = ['read', 'unread', 'archive', 'unarchive', 'mute', 'unmute', 'clear_priority', 'clear_ai'];

    public function __construct(
        private MarkWhatsappChatUnread $markUnread,
        private ArchiveWhatsappChat $archiveChat,
        private MuteWhatsappChat $muteChat,
        private SetWhatsappChatPriority $setPriority,
    ) {}

    /**
     * @param  array<int, mixed>  $chatIds
     * @return array{done: int, failed: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappAccount $account, string $action, array $chatIds): array
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        /** @var array{action: string, chats: list<string>} $validated */
        $validated = Validator::make(['action' => $action, 'chats' => array_values($chatIds)], [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'chats' => ['required', 'array', 'max:'.self::MAX_CHATS],
            'chats.*' => ['string', 'distinct'],
        ], [
            'chats.required' => __('Pick the chats first.'),
            'chats.max' => __('Up to :max chats at a time.', ['max' => self::MAX_CHATS]),
        ])->validate();

        $done = 0;
        $failed = 0;

        foreach ($account->targets()->whereIn('friendly_id', $validated['chats'])->get() as $chat) {
            try {
                $this->apply($actor, $chat, $validated['action']);
                $done++;
            } catch (EvolutionApiException $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * @throws EvolutionApiException
     */
    private function apply(User $actor, WhatsappTarget $chat, string $action): void
    {
        match ($action) {
            'read' => $chat->isUnread() ? $chat->forceFill(['unread_count' => 0, 'marked_unread' => false])->save() : null,
            'unread' => $chat->isUnread() ? null : ($this->markUnread)($actor, $chat, true, announce: false),
            'archive' => $chat->isArchived() ? null : ($this->archiveChat)($actor, $chat, true, announce: false),
            'unarchive' => $chat->isArchived() ? ($this->archiveChat)($actor, $chat, false, announce: false) : null,
            'mute' => ($this->muteChat)($actor, $chat, 'always'),
            'unmute' => ($this->muteChat)($actor, $chat, null),
            'clear_priority' => $chat->priority === null ? null : ($this->setPriority)($actor, $chat, null),
            'clear_ai' => $chat->forgetAiTriage()->save(),
            default => throw new InvalidArgumentException("Unknown bulk action [{$action}]."),
        };
    }
}
