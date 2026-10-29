<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Creates a WhatsApp group on the number, with a subject and members
 * picked among its contacts (contact chats, by friendly id), and returns
 * its chat. The number is its admin. WhatsApp leaves out, without a word,
 * who is not on it.
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class CreateWhatsappGroup
{
    use AuthorizesWhatsappModule;

    /**
     * WhatsApp's limit for a group's subject.
     */
    public const int MAX_SUBJECT = 100;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @param  list<string>  $memberIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappAccount $account, string $subject, array $memberIds, ?string $description = null): WhatsappTarget
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $account);

        /** @var array{subject: string, members: list<string>, description: string|null} $validated */
        $validated = Validator::make(
            ['subject' => mb_trim($subject), 'members' => $memberIds, 'description' => filled($description) ? mb_trim((string) $description) : null],
            [
                'subject' => ['required', 'string', 'max:'.self::MAX_SUBJECT],
                'members' => ['required', 'array', 'min:1', 'max:256'],
                'members.*' => ['string', 'distinct'],
                'description' => ['nullable', 'string', 'max:2048'],
            ],
        )->validate();

        if (! $account->isOpen()) {
            throw ValidationException::withMessages(['subject' => __('The WhatsApp account :nickname is not connected. Reconnect it first.', ['nickname' => $account->nickname])]);
        }

        $members = $account->targets()
            ->whereIn('friendly_id', $validated['members'])
            ->where('jid', 'like', '%'.WhatsappTarget::CONTACT_JID_SUFFIX)
            ->pluck('jid');

        if ($members->count() !== count($validated['members'])) {
            throw ValidationException::withMessages(['members' => __('Pick contacts of this number with a phone number.')]);
        }

        $jid = $this->client->createGroup(
            $account->instance_name,
            $validated['subject'],
            $members->map(fn (string $jid): string => (string) strstr($jid, '@', true))->values()->all(),
            $validated['description'],
        );

        $group = WhatsappTarget::query()->firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'jid' => $jid],
            [
                'whatsapp_target_type_id' => WhatsappTargetType::idFor(WhatsappTargetType::GROUP),
                'name' => $validated['subject'],
                'description' => $validated['description'],
                'is_synced' => true,
                'last_message_at' => now(),
            ],
        );

        WhatsappChatUpdated::dispatch($account->user_id, $account->friendly_id, $group->friendly_id);

        return $group;
    }
}
