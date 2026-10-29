<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's place in a workspace (`workspace_user`): the modules switched on
 * for them there (`module_workspace_user`, by the super admin), the color
 * they see the workspace in when they override its own, and whether it is
 * their default — where what they create goes when they pick none. The
 * super admin has every module in the workspaces they are a member of.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $color a key of Workspace::COLORS
 * @property bool $is_default
 * @property int|null $added_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
#[Fillable(['workspace_id', 'user_id', 'color', 'is_default', 'added_by_id'])]
class WorkspaceMembership extends Pivot
{
    protected $table = 'workspace_user';

    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Whether `$moduleSlug` is switched on here by a row. The super admin
     * needs none: see User::workspaceIdsWithModule().
     */
    public function hasModule(string $moduleSlug): bool
    {
        return $this->modules->contains('slug', $moduleSlug);
    }

    /**
     * The color the member sees the workspace in: their own, else the
     * workspace's.
     */
    public function effectiveColor(): string
    {
        return $this->color ?? $this->workspace->color;
    }

    /**
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_workspace_user', 'workspace_user_id')
            ->withPivot('enabled_by_id')
            ->withTimestamps();
    }

    /**
     * Whether the chat provider is switched on for the member here (the
     * workspace must have it on too: Workspace::hasChatProvider()).
     */
    public function hasChatProvider(string $providerSlug): bool
    {
        return $this->chatProviders->contains('slug', $providerSlug);
    }

    /**
     * @return BelongsToMany<ChatProvider, $this>
     */
    public function chatProviders(): BelongsToMany
    {
        return $this->belongsToMany(ChatProvider::class, 'chat_provider_workspace_user', 'workspace_user_id')
            ->withPivot('enabled_by_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
