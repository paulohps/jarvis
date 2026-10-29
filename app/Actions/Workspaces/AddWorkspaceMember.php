<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin adds a user to a workspace, with the modules switched on
 * for them there (module slugs) and, optionally, the color they see it in.
 * The user's first workspace becomes their default. Modules the user had
 * nowhere before are announced (SetMemberModules).
 */
final readonly class AddWorkspaceMember
{
    use ValidatesWorkspace;

    public function __construct(private SetMemberModules $setMemberModules) {}

    /**
     * @param  array<string, mixed>  $input  `modules` (list of slugs), `color`
     *
     * @throws AuthorizationException
     * @throws ValidationException when the user is already a member or is deleted
     */
    public function __invoke(User $actor, Workspace $workspace, User $user, array $input = []): WorkspaceMembership
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{modules?: list<string>, color?: string|null} $validated */
        $validated = Validator::make($input, [
            ...$this->moduleRules(),
            'color' => $this->colorRules(required: false),
        ])->validate();

        if ($user->trashed()) {
            throw ValidationException::withMessages(['user' => __('The selected user is invalid.')]);
        }

        if ($workspace->memberships()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => __(':name is already a member.', ['name' => $user->name])]);
        }

        $membership = DB::transaction(fn (): WorkspaceMembership => WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'color' => $validated['color'] ?? null,
            'is_default' => ! WorkspaceMembership::query()->where('user_id', $user->id)->exists(),
            'added_by_id' => $actor->id,
        ]));

        $user->forgetMemberships();

        if (! $user->isSuperAdmin()) {
            ($this->setMemberModules)($actor, $membership, $validated['modules'] ?? []);
        }

        return $membership;
    }
}
