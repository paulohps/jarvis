<?php

namespace App\Actions\Workspaces;

use App\Models\Module;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Notifications\NotificationCenter;
use App\Services\Notifications\Types\ModuleEnabled;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The super admin sets which modules are on for a member in a workspace
 * (US-2.1): exactly the slugs given, with the super admin in
 * `enabled_by_id` of the new rows. A module the user had in no workspace
 * before gets the `module_enabled` notification; switching one on where
 * they already had it elsewhere only widens what they see, quietly.
 * Switching one off leaves the data in the workspace. Access changes on the
 * user's next request.
 *
 * The super admin has every module in their workspaces without rows, so
 * their memberships are never a target.
 */
final readonly class SetMemberModules
{
    use ValidatesWorkspace;

    public function __construct(private NotificationCenter $notificationCenter) {}

    /**
     * @param  list<string>|mixed  $moduleSlugs
     * @return list<string> the slugs switched on now
     *
     * @throws AuthorizationException
     * @throws ValidationException when the member is the super admin or a slug is unknown
     */
    public function __invoke(User $actor, WorkspaceMembership $membership, mixed $moduleSlugs): array
    {
        Gate::forUser($actor)->authorize('super-admin');

        /** @var array{modules: list<string>} $validated */
        $validated = Validator::make(['modules' => $moduleSlugs], [
            'modules' => ['present', 'array'],
            'modules.*' => $this->moduleRules()['modules.*'],
        ])->validate();

        $user = $membership->user;

        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user' => __('The super admin always has every module.'),
            ]);
        }

        $hadBefore = collect(Module::query()->active()->pluck('slug'))
            ->filter(fn (string $slug): bool => $user->hasModule($slug))
            ->all();

        $changes = $membership->modules()->syncWithPivotValues($this->moduleIds($validated['modules']), ['enabled_by_id' => $actor->id], detaching: true);

        $membership->unsetRelation('modules');
        $user->forgetMemberships();

        $attached = Module::query()->whereKey($changes['attached'])->get();

        foreach ($attached as $module) {
            if (! in_array($module->slug, $hadBefore, true)) {
                $this->notificationCenter->dispatch(new ModuleEnabled($module), $user);
            }
        }

        return $attached->pluck('slug')->values()->all();
    }
}
