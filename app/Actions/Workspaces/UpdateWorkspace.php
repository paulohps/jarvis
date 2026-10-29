<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin renames a workspace or changes its color; the fields left
 * out stay. Members who chose their own color keep seeing it.
 */
final readonly class UpdateWorkspace
{
    use ValidatesWorkspace;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, Workspace $workspace, array $input): Workspace
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{name?: string, color?: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:'.Workspace::MAX_NAME_LENGTH],
            'color' => ['sometimes', ...$this->colorRules(required: true)],
        ])->validate();

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }

        $workspace->update($validated);

        return $workspace;
    }
}
