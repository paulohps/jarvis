<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\AiProviderNotConfigured;
use App\Jobs\TriageWhatsappChats;
use App\Models\AiPurpose;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Services\Ai\AiRoute;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Asks the AI to triage unread chats of one of the user's numbers, picked
 * by their friendly ids (up to MAX_CHATS unread ones): they are marked as waiting for
 * it (`ai_triage_requested_at`) and TriageWhatsappChats does it in the
 * background. The chats picked that are not unread are left out; the
 * priority the user gave them is never touched.
 *
 * Only the owner of the account, with the `whatsapp` module, and only with
 * an AI set up for the WhatsApp replies.
 */
final readonly class RequestWhatsappChatTriage
{
    use AuthorizesWhatsappModule;

    public const int MAX_CHATS = 20;

    /**
     * @param  array<int, mixed>  $chatIds
     * @return int how many chats the AI triages
     *
     * @throws AuthorizationException
     * @throws AiProviderNotConfigured
     * @throws ValidationException
     */
    public function __invoke(User $actor, WhatsappAccount $account, array $chatIds): int
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        /** @var array{chats: list<string>} $validated */
        $validated = Validator::make(['chats' => array_values($chatIds)], [
            'chats' => ['required', 'array', 'max:'.ApplyWhatsappChatsBulkAction::MAX_CHATS],
            'chats.*' => ['string', 'distinct'],
        ], [
            'chats.required' => __('Pick the unread chats for the AI to prioritize.'),
            'chats.max' => __('Up to :max chats at a time.', ['max' => ApplyWhatsappChatsBulkAction::MAX_CHATS]),
        ])->validate();

        if (AiRoute::for(AiPurpose::WHATSAPP_REPLY) === null) {
            throw new AiProviderNotConfigured;
        }

        $chats = $account->targets()
            ->whereIn('friendly_id', $validated['chats'])
            ->where(fn ($query) => $query->where('unread_count', '>', 0)->orWhere('marked_unread', true))
            ->get();

        if ($chats->isEmpty()) {
            throw ValidationException::withMessages(['chats' => __('Pick the unread chats for the AI to prioritize.')]);
        }

        if ($chats->count() > self::MAX_CHATS) {
            throw ValidationException::withMessages(['chats' => __('The AI prioritizes up to :max chats at a time.', ['max' => self::MAX_CHATS])]);
        }

        WhatsappTarget::query()->whereKey($chats->modelKeys())->update(['ai_triage_requested_at' => now()]);

        TriageWhatsappChats::dispatch($actor, $account, array_values($chats->map(fn (WhatsappTarget $chat): int => $chat->id)->all()));

        return $chats->count();
    }
}
