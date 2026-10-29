<?php

namespace App\Actions\Invitations;

use App\Exceptions\InvalidInvitation;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accepts an invitation with a name and a password (US-1.2): creates the
 * account with the invited e-mail and the invitation's language, makes it a
 * member of the invitation's workspace — its default — with exactly the
 * invitation's modules there, marks it accepted and signs the new user in.
 *
 * A token that is unknown, expired, revoked or already used creates nothing.
 * The invitation row is locked while accepting, so two submits of the same
 * link create a single account.
 */
final readonly class AcceptInvitation
{
    /**
     * @param  array{name?: mixed, password?: mixed, password_confirmation?: mixed}  $input
     *
     * @throws InvalidInvitation
     * @throws ValidationException
     */
    public function __invoke(string $token, array $input): User
    {
        $invitation = self::pendingInvitation($token);

        /** @var array{name: string, password: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ])->validate();

        $user = $this->createAccount($invitation, $validated['name'], $validated['password']);

        Auth::guard('web')->login($user);

        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        return $user;
    }

    /**
     * The pending invitation of a link token.
     *
     * @throws InvalidInvitation
     */
    public static function pendingInvitation(string $token): Invitation
    {
        $invitation = Invitation::findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            throw InvalidInvitation::make();
        }

        return $invitation;
    }

    /**
     * Creates the invited user and accepts the invitation, without signing
     * in. `password` is null for an account that signs in with Google.
     *
     * @throws InvalidInvitation when the invitation stopped being pending or the e-mail got an account meanwhile
     */
    public function createAccount(Invitation $invitation, string $name, ?string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password): User {
            /** @var Invitation|null $locked */
            $locked = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isPending()
                || User::withTrashed()->whereRaw('lower(email) = ?', [$locked->email])->exists()) {
                throw InvalidInvitation::make();
            }

            $user = new User([
                'name' => $name,
                'email' => $locked->email,
                'password' => $password,
                'locale_id' => $locked->locale_id,
            ]);
            // The invitation e-mail reached the address, which proves it.
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($locked->workspace_id !== null) {
                $membership = WorkspaceMembership::query()->create([
                    'workspace_id' => $locked->workspace_id,
                    'user_id' => $user->id,
                    'is_default' => true,
                    'added_by_id' => $locked->invited_by_id,
                ]);

                $membership->modules()->sync(
                    $locked->modules()->pluck('modules.id')
                        ->mapWithKeys(fn (int $moduleId): array => [$moduleId => ['enabled_by_id' => $locked->invited_by_id]])
                        ->all(),
                );
            }

            $locked->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            $invitation->setRawAttributes($locked->getAttributes(), true);

            return $user;
        });
    }
}
