<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shared data that belongs to a workspace (`workspace_id`): seen by the
 * members of that workspace with the model's module switched on
 * (`workspaceModule()`). A user in several workspaces sees all of theirs
 * together; nobody sees another workspace's rows — the super admin
 * included, unless they are a member.
 *
 * There is no global scope: queues and the scheduler read every workspace.
 * Whatever a user reads goes through `visibleTo()`, and whatever they act
 * on through `isVisibleTo()`.
 *
 * @property int $workspace_id
 * @property-read Workspace $workspace
 */
trait BelongsToWorkspace
{
    /**
     * The module whose data the model is (a Module slug).
     */
    abstract public static function workspaceModule(): string;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Only the rows of the workspaces where `$user` has the model's module.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('workspace_id'), $user->workspaceIdsWithModule(static::workspaceModule()));
    }

    /**
     * Whether `$user` sees this row: a member of its workspace with the
     * model's module.
     */
    public function isVisibleTo(User $user): bool
    {
        return $user->hasModuleIn($this->workspace_id, static::workspaceModule());
    }
}
