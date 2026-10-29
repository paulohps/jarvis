<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin creates a workspace: a name, up to 60 characters, and a
 * color (Workspace::COLORS). It starts without members.
 */
final readonly class CreateWorkspace
{
    use ValidatesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input): Workspace
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{name: string, color: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:'.Workspace::MAX_NAME_LENGTH],
            'color' => $this->colorRules(required: true),
        ])->validate();

        return Workspace::query()->create([
            'name' => trim($validated['name']),
            'color' => $validated['color'],
            'created_by_id' => $actor->id,
        ]);
    }
}
