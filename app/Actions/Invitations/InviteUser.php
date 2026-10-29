<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use App\Models\Locale;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Localization;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The super admin invites someone to a workspace (US-1.1): an e-mail, the
 * workspace (friendly id) they join, the modules switched on for them there
 * when the invitation is accepted and the language of the e-mail and of the
 * account.
 *
 * The link token is random and only its sha256 is stored; the plain token
 * only travels in the `invitation` e-mail. The invitation is valid for 7
 * days. Inviting an address again revokes its earlier pending invitation, so
 * a single link works at a time.
 */
final readonly class InviteUser
{
    public function __construct(private SendInvitation $sendInvitation) {}

    /**
     * @param  array{email?: mixed, workspace?: mixed, locale?: mixed, modules?: mixed}  $input  `modules` is a list of module slugs
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Invitation
    {
        Gate::forUser($actor)->authorize('create', Invitation::class);

        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        /** @var array{email: string, workspace: string, locale?: string|null, modules?: list<string>} $validated */
        $validated = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255', $this->mustNotHaveAnAccount()],
            'workspace' => ['required', 'string', Rule::exists('workspaces', 'friendly_id')],
            'locale' => ['nullable', 'string', Rule::in(Localization::SUPPORTED_LOCALES)],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'distinct', Rule::exists('modules', 'slug')->where('is_active', true)],
        ])->validate();

        $token = Invitation::newToken();

        $invitation = DB::transaction(function () use ($actor, $validated, $token): Invitation {
            Invitation::query()
                ->pending()
                ->forEmail($validated['email'])
                ->update(['revoked_at' => now()]);

            $invitation = Invitation::query()->create([
                'email' => $validated['email'],
                'token_hash' => Invitation::hashToken($token),
                'locale_id' => Locale::idFor($validated['locale'] ?? Localization::DEFAULT_LOCALE),
                'workspace_id' => Workspace::query()->where('friendly_id', $validated['workspace'])->value('id'),
                'invited_by_id' => $actor->id,
                'expires_at' => now()->addDays(Invitation::VALIDITY_DAYS),
            ]);

            $invitation->modules()->sync(array_map(
                static fn (string $slug): int => Module::idFor($slug),
                $validated['modules'] ?? [],
            ));

            return $invitation;
        });

        ($this->sendInvitation)($invitation, $token);

        return $invitation;
    }

    /**
     * Refuses an address that already has an account, deleted ones included:
     * the e-mail stays unique among them.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function mustNotHaveAnAccount(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (User::withTrashed()->whereRaw('lower(email) = ?', [$value])->exists()) {
                $fail(__('User already exists.'));
            }
        };
    }
}
