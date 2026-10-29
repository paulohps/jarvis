<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Changes a membership's own color (null goes back to the workspace's) or
 * makes it the member's default workspace — where what they create goes
 * when they pick none. The member does it for themselves, or the super
 * admin for anyone. A default cannot be unset, only moved to another one.
 */
final readonly class UpdateWorkspaceMember
{
    use ValidatesWorkspace;

    /**
     * @param  array<string, mixed>  $input  `color` (nullable), `is_default` (true only)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, WorkspaceMembership $membership, array $input): WorkspaceMembership
    {
        if ($actor->id !== $membership->user_id && ! $actor->isSuperAdmin()) {
            throw new AuthorizationException;
        }

        /** @var array{color?: string|null, is_default?: bool} $validated */
        $validated = Validator::make($input, [
            'color' => ['sometimes', ...$this->colorRules(required: false)],
            'is_default' => ['sometimes', 'boolean', 'accepted'],
        ])->validate();

        DB::transaction(function () use ($membership, $validated): void {
            if (array_key_exists('color', $validated)) {
                $membership->color = $validated['color'];
            }

            if (($validated['is_default'] ?? false) && ! $membership->is_default) {
                WorkspaceMembership::query()
                    ->where('user_id', $membership->user_id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);

                $membership->is_default = true;
            }

            $membership->save();
        });

        $membership->user->forgetMemberships();

        return $membership;
    }
}
