<?php

namespace App\Actions\Auth;

use App\Actions\Invitations\AcceptInvitation;
use App\Exceptions\GoogleSignInRefused;
use App\Exceptions\InvalidInvitation;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\SocialProvider;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as GoogleUser;

/**
 * Finds the account a Google identity signs in to (US-1.3), in this order:
 *
 * 1. the user the Google account is already linked to;
 * 2. the user with the same e-mail, linking the Google account to it;
 * 3. a valid pending invitation for the e-mail: the account is created with
 *    the invitation's modules and the invitation is accepted;
 * 4. otherwise it is refused ("invitation required") and nothing is created.
 *
 * When the visitor started from an invitation link, the Google e-mail must be
 * the invited one. Only a verified Google e-mail counts, and a deactivated or
 * deleted user is refused. Each sign-in updates `social_accounts.last_used_at`.
 *
 * It does not open a session: the web callback signs the user in.
 */
final readonly class AuthenticateWithGoogle
{
    public function __construct(private AcceptInvitation $acceptInvitation) {}

    /**
     * @param  string|null  $invitationToken  the token of the invitation link the visitor came from
     *
     * @throws GoogleSignInRefused
     */
    public function __invoke(GoogleUser $googleUser, ?string $invitationToken = null): User
    {
        $provider = SocialProvider::bySlug(SocialProvider::GOOGLE);

        if (! $provider->is_active) {
            throw GoogleSignInRefused::unavailable();
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));

        if ($email === '' || ! $this->isVerified($googleUser)) {
            throw GoogleSignInRefused::unverifiedEmail();
        }

        $invitation = $invitationToken === null ? null : $this->invitationOfLink($invitationToken, $email);

        $linkedAccount = SocialAccount::query()
            ->where('social_provider_id', $provider->id)
            ->where('provider_user_id', (string) $googleUser->getId())
            ->first();

        if ($linkedAccount !== null) {
            $user = $this->ensureCanSignIn(User::withTrashed()->find($linkedAccount->user_id));

            $linkedAccount->update(['email' => $email, 'last_used_at' => now()]);

            return $user;
        }

        $existingUser = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        if ($existingUser !== null) {
            $user = $this->ensureCanSignIn($existingUser);
            $this->link($user, $provider, $googleUser, $email);

            return $user;
        }

        $invitation ??= Invitation::query()->pending()->forEmail($email)->latest('id')->first();

        if ($invitation === null) {
            throw GoogleSignInRefused::invitationRequired();
        }

        try {
            $user = $this->acceptInvitation->createAccount($invitation, $this->nameOf($googleUser, $email), null);
        } catch (InvalidInvitation) {
            throw GoogleSignInRefused::invalidInvitation();
        }

        $this->link($user, $provider, $googleUser, $email);

        return $user;
    }

    /**
     * The pending invitation of the link the visitor came from, which must
     * be for the Google e-mail.
     *
     * @throws GoogleSignInRefused
     */
    private function invitationOfLink(string $token, string $email): Invitation
    {
        try {
            $invitation = AcceptInvitation::pendingInvitation($token);
        } catch (InvalidInvitation) {
            throw GoogleSignInRefused::invalidInvitation();
        }

        if ($invitation->email !== $email) {
            throw GoogleSignInRefused::emailMismatch();
        }

        return $invitation;
    }

    /**
     * @throws GoogleSignInRefused
     */
    private function ensureCanSignIn(?User $user): User
    {
        if ($user === null || $user->trashed() || $user->isDeactivated()) {
            throw GoogleSignInRefused::deactivated();
        }

        return $user;
    }

    /**
     * Links the Google account to the user, replacing an earlier Google
     * identity of the same user.
     */
    private function link(User $user, SocialProvider $provider, GoogleUser $googleUser, string $email): void
    {
        SocialAccount::query()->updateOrCreate(
            ['user_id' => $user->id, 'social_provider_id' => $provider->id],
            ['provider_user_id' => (string) $googleUser->getId(), 'email' => $email, 'last_used_at' => now()],
        );
    }

    private function isVerified(GoogleUser $googleUser): bool
    {
        $raw = method_exists($googleUser, 'getRaw') ? $googleUser->getRaw() : [];

        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function nameOf(GoogleUser $googleUser, string $email): string
    {
        $name = trim((string) $googleUser->getName());

        return $name !== '' ? Str::limit($name, 255, '') : Str::before($email, '@');
    }
}
