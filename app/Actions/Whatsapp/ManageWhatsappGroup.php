<?php

namespace App\Actions\Whatsapp;

use App\Events\WhatsappChatUpdated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manages a WhatsApp group of the number, as its admin would on the
 * phone: its subject, description and picture, its members (add, remove,
 * make or dismiss as admin), who may send messages and edit its info, its
 * disappearing messages, its invite link, and leaving it. WhatsApp refuses
 * (an EvolutionApiException) what only an admin may do when the number is
 * none.
 *
 * Only the owner of the account, with the `whatsapp` module, while the
 * number is connected.
 */
final readonly class ManageWhatsappGroup
{
    use AuthorizesWhatsappChat;

    /**
     * What can be done to members.
     *
     * @var list<string>
     */
    public const array MEMBER_ACTIONS = ['add', 'remove', 'promote', 'demote'];

    /**
     * WhatsApp's limit for a group picture file.
     */
    public const int MAX_PICTURE_KILOBYTES = 5 * 1024;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * The group's info as WhatsApp has it now: its subject, description and
     * timer are kept, and who may send messages (`announce`: admins only)
     * and edit its info (`restrict`: admins only) are returned.
     *
     * @return array{announce: bool, restrict: bool}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function refresh(User $actor, WhatsappTarget $group): array
    {
        $this->authorizeGroup($actor, $group, 'group');

        $details = $this->client->groupDetailsOf($group->account->instance_name, $group->jid);

        $group->forceFill(array_filter([
            'name' => $details['name'],
            'description' => $details['description'],
            'ephemeral_seconds' => $details['ephemeral'],
        ], fn (mixed $value): bool => $value !== null));

        if ($group->isDirty()) {
            $group->save();
            $this->changed($group, []);
        }

        return ['announce' => $details['announce'], 'restrict' => $details['restrict']];
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function rename(User $actor, WhatsappTarget $group, string $subject): WhatsappTarget
    {
        $this->authorizeGroup($actor, $group, 'subject');

        /** @var array{subject: string} $validated */
        $validated = Validator::make(['subject' => mb_trim($subject)], ['subject' => ['required', 'string', 'max:'.CreateWhatsappGroup::MAX_SUBJECT]])->validate();

        $this->client->updateGroupSubject($group->account->instance_name, $group->jid, $validated['subject']);

        return $this->changed($group, ['name' => $validated['subject']]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function describe(User $actor, WhatsappTarget $group, string $description): WhatsappTarget
    {
        $this->authorizeGroup($actor, $group, 'description');

        /** @var array{description: string|null} $validated */
        $validated = Validator::make(['description' => mb_trim($description) !== '' ? mb_trim($description) : null], ['description' => ['nullable', 'string', 'max:2048']])->validate();

        $this->client->updateGroupDescription($group->account->instance_name, $group->jid, (string) $validated['description']);

        return $this->changed($group, ['description' => $validated['description']]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function changePicture(User $actor, WhatsappTarget $group, UploadedFile $picture): WhatsappTarget
    {
        $this->authorizeGroup($actor, $group, 'picture');

        Validator::make(['picture' => $picture], ['picture' => ['required', 'file', 'mimetypes:image/jpeg,image/png', 'max:'.self::MAX_PICTURE_KILOBYTES]])->validate();

        $this->client->updateGroupPicture($group->account->instance_name, $group->jid, (string) $picture->get());

        // The new picture comes with the next refresh (RefreshWhatsappChatPicture).
        return $this->changed($group, ['picture_checked_at' => null]);
    }

    /**
     * Adds (numbers with the country code, or JIDs), removes, promotes or
     * demotes members; returns, by JID, whether WhatsApp did it.
     *
     * @param  list<string>  $participants
     * @return array<string, bool>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function updateMembers(User $actor, WhatsappTarget $group, string $action, array $participants): array
    {
        $this->authorizeGroup($actor, $group, 'participants');

        Validator::make(['action' => $action, 'participants' => $participants], [
            'action' => ['required', Rule::in(self::MEMBER_ACTIONS)],
            'participants' => ['required', 'array', 'min:1', 'max:50'],
            'participants.*' => ['string', 'max:80', 'regex:/^(\+?\d{8,15}|[\w.:-]+@(s\.whatsapp\.net|lid))$/'],
        ])->validate();

        $participants = array_map(fn (string $participant): string => str_contains($participant, '@') ? $participant : (string) preg_replace('/\D/', '', $participant), $participants);

        $results = $this->client->updateGroupParticipants($group->account->instance_name, $group->jid, $action, $participants);

        Cache::forget($group->participantsCacheKey());
        $this->changed($group, []);

        return $results;
    }

    /**
     * Who may send messages (`$adminsOnly` for admins only) and edit the
     * group's info (`$infoAdminsOnly`); null leaves that one as it is.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function setPermissions(User $actor, WhatsappTarget $group, ?bool $adminsOnly, ?bool $infoAdminsOnly): void
    {
        $this->authorizeGroup($actor, $group, 'settings');

        if ($adminsOnly !== null) {
            $this->client->updateGroupSetting($group->account->instance_name, $group->jid, $adminsOnly ? 'announcement' : 'not_announcement');
        }

        if ($infoAdminsOnly !== null) {
            $this->client->updateGroupSetting($group->account->instance_name, $group->jid, $infoAdminsOnly ? 'locked' : 'unlocked');
        }
    }

    /**
     * Turns disappearing messages on (a timer of WhatsappTarget::EPHEMERAL_TIMERS)
     * or off (0).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function setTimer(User $actor, WhatsappTarget $group, int $seconds): WhatsappTarget
    {
        $this->authorizeGroup($actor, $group, 'ephemeral');

        Validator::make(['ephemeral' => $seconds], ['ephemeral' => ['required', 'integer', Rule::in(array_keys(WhatsappTarget::EPHEMERAL_TIMERS))]])->validate();

        $this->client->toggleGroupEphemeral($group->account->instance_name, $group->jid, $seconds);

        return $this->changed($group, ['ephemeral_seconds' => $seconds]);
    }

    /**
     * The link to join the group; with `$revoke`, a new one (the current
     * one stops working).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function inviteLink(User $actor, WhatsappTarget $group, bool $revoke = false): string
    {
        $this->authorizeGroup($actor, $group, 'invite');

        return $this->client->groupInviteLink($group->account->instance_name, $group->jid, $revoke);
    }

    /**
     * The number leaves the group; the chat stays in Jarvis with its
     * messages, archived.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    public function leave(User $actor, WhatsappTarget $group): WhatsappTarget
    {
        $this->authorizeGroup($actor, $group, 'group');

        $this->client->leaveGroup($group->account->instance_name, $group->jid);
        Cache::forget($group->participantsCacheKey());

        return $this->changed($group, ['archived_at' => $group->archived_at ?? now()]);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function authorizeGroup(User $actor, WhatsappTarget $group, string $field): void
    {
        $this->authorizeConnectedChat($actor, $group, $field);
        $this->ensureGroup($group, $field);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function changed(WhatsappTarget $group, array $attributes): WhatsappTarget
    {
        if ($attributes !== []) {
            $group->forceFill($attributes)->save();
        }

        WhatsappChatUpdated::dispatch($group->account->user_id, $group->account->friendly_id, $group->friendly_id);

        return $group;
    }
}
