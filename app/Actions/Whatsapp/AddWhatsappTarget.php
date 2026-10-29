<?php

namespace App\Actions\Whatsapp;

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
 * Adds a contact or a group to an account by hand (`is_synced` false), for
 * one the sync did not bring:
 *
 * - a contact by its number in international format (`+55 11 99999-0000` →
 *   `5511999990000@s.whatsapp.net`);
 * - a group by its invite link (`https://chat.whatsapp.com/<code>`, or the
 *   code alone): the Evolution API tells the group's id and subject, which
 *   becomes the name when none is typed; with `$joinGroup` the number also
 *   joins it (skipped when the group is already synced, i.e. it is in it).
 *   Needs the account connected;
 * - a group by its id (`120363000000000001`, with or without `@g.us`).
 *
 * A later sync that finds the same JID marks it synced and fills the name
 * it brings. A JID the account already has is refused. Only the owner may
 * do it, with the `whatsapp` module.
 */
final readonly class AddWhatsappTarget
{
    use AuthorizesWhatsappModule;

    /**
     * A group id: digits, or the older `creator-timestamp` form.
     */
    public const string GROUP_ID_PATTERN = '/^\d{5,30}(-\d{5,20})?$/';

    /**
     * The code of an invite link, or the code alone (letters and digits,
     * with at least one letter, so a group id is never taken for one).
     */
    public const string INVITE_PATTERN = '~^(?:(?:https?://)?chat\.whatsapp\.com/(?:invite/)?)?(?=[A-Za-z0-9]*[A-Za-z])([A-Za-z0-9]{10,40})/?(?:\?.*)?$~';

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws EvolutionApiException when the Evolution API cannot be reached
     */
    public function __invoke(User $user, WhatsappAccount $account, string $type, string $identifier, ?string $name = null, bool $joinGroup = false): WhatsappTarget
    {
        $this->authorizeWhatsapp($user);
        Gate::forUser($user)->authorize('use', $account);

        /** @var array{type: string, name: string|null} $validated */
        $validated = Validator::make(
            ['type' => $type, 'name' => filled($name) ? mb_trim((string) $name) : null],
            [
                'type' => ['required', 'in:'.WhatsappTargetType::CONTACT.','.WhatsappTargetType::GROUP],
                'name' => ['nullable', 'string', 'max:255'],
            ],
        )->validate();

        $identifier = mb_trim($identifier);
        $targetName = $validated['name'];

        if ($validated['type'] === WhatsappTargetType::CONTACT) {
            $jid = WhatsappTarget::contactJidFor($this->validIdentifier(
                (string) preg_replace('/[\s\-().]/', '', $identifier),
                WhatsappMessageFields::E164_PATTERN,
                __('Type the number in international format, such as +5511999990000.'),
            ));
        } elseif (preg_match(self::INVITE_PATTERN, $identifier, $matches) === 1) {
            $group = $this->groupFromInvite($account, $matches[1], $joinGroup);
            $jid = $group['jid'];
            $targetName ??= $group['name'];
        } else {
            $jid = $this->validIdentifier(
                (string) preg_replace('/@g\.us\z/', '', $identifier),
                self::GROUP_ID_PATTERN,
                __('Paste the group’s invite link, or type its id: only digits, such as 120363000000000001.'),
            ).'@g.us';
        }

        if ($account->targets()->where('jid', $jid)->exists()) {
            throw ValidationException::withMessages([
                'identifier' => $validated['type'] === WhatsappTargetType::GROUP
                    ? __('This group is already in the list.')
                    : __('This contact is already in the list.'),
            ]);
        }

        return $account->targets()->create([
            'whatsapp_target_type_id' => WhatsappTargetType::idFor($validated['type']),
            'jid' => $jid,
            'name' => $targetName,
            'is_synced' => false,
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function validIdentifier(string $identifier, string $pattern, string $message): string
    {
        Validator::make(
            ['identifier' => $identifier],
            ['identifier' => ['required', 'string', 'regex:'.$pattern]],
            ['identifier.required' => $message, 'identifier.regex' => $message],
        )->validate();

        return $identifier;
    }

    /**
     * The group behind an invite code, joined when asked.
     *
     * @return array{jid: string, name: string|null}
     *
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    private function groupFromInvite(WhatsappAccount $account, string $inviteCode, bool $joinGroup): array
    {
        if (! $account->isOpen()) {
            throw ValidationException::withMessages(['identifier' => __('Connect the number first: the invite link is read through it.')]);
        }

        $group = $this->refusalAsValidation(
            fn (): ?array => $this->client->groupInviteInfo($account->instance_name, $inviteCode),
            __('This invite link is invalid or was revoked.'),
        );

        if ($group === null) {
            throw ValidationException::withMessages(['identifier' => __('This invite link is invalid or was revoked.')]);
        }

        $isMember = $account->targets()->where('jid', $group['jid'])->where('is_synced', true)->exists();

        if ($joinGroup && ! $isMember) {
            $this->refusalAsValidation(
                fn (): ?string => $this->client->acceptGroupInvite($account->instance_name, $inviteCode),
                __('The number could not join this group. It may need an admin’s approval, or the link was revoked.'),
            );
        }

        return $group;
    }

    /**
     * Runs an Evolution API call, turning a refusal (status 400–499) into a
     * validation error on the link; no answer at all stays an
     * EvolutionApiException.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $call
     * @return TResult
     *
     * @throws ValidationException
     * @throws EvolutionApiException
     */
    private function refusalAsValidation(callable $call, string $message): mixed
    {
        try {
            return $call();
        } catch (EvolutionApiException $exception) {
            if ($exception->status !== null && $exception->status >= 400 && $exception->status < 500) {
                throw ValidationException::withMessages(['identifier' => $message]);
            }

            throw $exception;
        }
    }
}
