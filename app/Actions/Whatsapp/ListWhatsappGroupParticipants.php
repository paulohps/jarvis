<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The members of a group as WhatsApp has them now, admins first, each
 * named after the account's contact with that JID (else the name WhatsApp
 * knows, else the number), and whether it is the number itself. Fetched
 * at most every CACHE_MINUTES, sooner when WhatsApp says they changed
 * (WhatsappTarget::participantsCacheKey()).
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class ListWhatsappGroupParticipants
{
    use AuthorizesWhatsappChat;

    public const int CACHE_MINUTES = 10;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @return list<array{jid: string, lid: string|null, name: string, number: string|null, admin: bool, me: bool}>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function __invoke(User $actor, WhatsappTarget $chat): array
    {
        $this->authorizeConnectedChat($actor, $chat, 'participants');
        $this->ensureGroup($chat, 'participants');

        $account = $chat->account;

        /** @var list<array{jid: string, lid: string|null, admin: string|null, name: string|null}> $participants */
        $participants = Cache::remember($chat->participantsCacheKey(), now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => $this->client->groupParticipants($account->instance_name, $chat->jid));

        $jids = array_merge(array_column($participants, 'jid'), array_filter(array_column($participants, 'lid')));
        $contacts = $account->targets()
            ->where(fn ($query) => $query->whereIn('jid', $jids)->orWhereIn('lid', $jids))
            ->get();
        $names = $contacts->mapWithKeys(fn (WhatsappTarget $contact): array => [$contact->jid => $contact->displayName()])->all()
            + $contacts->filter(fn (WhatsappTarget $contact): bool => $contact->lid !== null)->mapWithKeys(fn (WhatsappTarget $contact): array => [(string) $contact->lid => $contact->displayName()])->all();
        $ownDigits = preg_replace('/\D/', '', (string) $account->phone_number);

        $members = array_map(function (array $participant) use ($names, $ownDigits): array {
            $number = str_ends_with($participant['jid'], WhatsappTarget::CONTACT_JID_SUFFIX) ? '+'.strstr($participant['jid'], '@', true) : null;
            $me = $number !== null && $ownDigits !== '' && $number === '+'.$ownDigits;

            return [
                'jid' => $participant['jid'],
                'lid' => $participant['lid'],
                'name' => $me ? __('You') : ($names[$participant['jid']] ?? ($participant['lid'] !== null ? $names[$participant['lid']] ?? null : null) ?? $participant['name'] ?? $number ?? $participant['jid']),
                'number' => $number,
                'admin' => $participant['admin'] !== null,
                'me' => $me,
            ];
        }, $participants);

        usort($members, fn (array $a, array $b): int => [$b['me'], $b['admin'], $a['name']] <=> [$a['me'], $a['admin'], $b['name']]);

        return $members;
    }
}
