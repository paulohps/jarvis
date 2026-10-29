<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of people sharing data — a family (Key Concept "Workspace"). The
 * shopping lists, the agenda and the invoices belong to a workspace and are
 * seen by its members; a user may be in several and sees all of theirs
 * together, each told apart by its color. The modules are switched on per
 * membership (WorkspaceMembership). Only the super admin manages them.
 *
 * The personal data — WhatsApp numbers, mail services, Google calendars —
 * stays the user's own, outside any workspace.
 *
 * @property int $id
 * @property string $friendly_id
 * @property string $name
 * @property string $color a key of COLORS
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $memberships_count
 */
#[Fillable(['friendly_id', 'name', 'color', 'created_by_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasFriendlyId;

    public const int MAX_NAME_LENGTH = 60;

    /**
     * The colors a workspace, or a member's view of it, may have: the name
     * stored and its hex.
     *
     * @var array<string, string>
     */
    public const array COLORS = [
        'red' => '#ff3b30',
        'orange' => '#ff9500',
        'yellow' => '#ffcc00',
        'green' => '#34c759',
        'mint' => '#00c7be',
        'teal' => '#30b0c7',
        'cyan' => '#32ade6',
        'blue' => '#007aff',
        'indigo' => '#5856d6',
        'purple' => '#af52de',
        'pink' => '#ff2d55',
        'brown' => '#a2845e',
        'gray' => '#8e8e93',
    ];

    /**
     * The tables whose rows belong to a workspace; one with rows cannot be
     * deleted.
     *
     * @var list<string>
     */
    public const array SCOPED_TABLES = [
        'shopping_lists',
        'shopping_list_schedules',
        'reminders',
        'payers',
        'issuers',
        'invoice_recurrences',
        'invoices',
    ];

    public static function idPrefix(): string
    {
        return 'wsp';
    }

    /**
     * The hex of a color name, gray when it is not one of COLORS.
     */
    public static function hexOf(?string $color): string
    {
        return self::COLORS[$color] ?? self::COLORS['gray'];
    }

    /**
     * Whether any shared data belongs to it (soft-deleted rows included).
     */
    public function hasData(): bool
    {
        foreach (self::SCOPED_TABLES as $table) {
            if ($this->getConnection()->table($table)->where('workspace_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'color', 'is_default'])
            ->withTimestamps();
    }

    /**
     * The members who may use `$moduleSlug` here: the super admin among the
     * members always, the others with the module switched on.
     *
     * @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'>
     */
    public function membersWithModule(string $moduleSlug): BelongsToMany
    {
        return $this->members()->where(fn ($query) => $query
            ->where('users.is_super_admin', true)
            ->orWhereExists(fn ($exists) => $exists
                ->selectRaw('1')
                ->from('module_workspace_user')
                ->join('modules', 'modules.id', '=', 'module_workspace_user.module_id')
                ->whereColumn('module_workspace_user.workspace_user_id', 'workspace_user.id')
                ->where('modules.slug', $moduleSlug)));
    }

    /**
     * The chat providers the super admin switched on for the workspace: a
     * member may use one only when it is on here and for them.
     *
     * @return BelongsToMany<ChatProvider, $this>
     */
    public function chatProviders(): BelongsToMany
    {
        return $this->belongsToMany(ChatProvider::class)
            ->withPivot('enabled_by_id')
            ->withTimestamps();
    }

    public function hasChatProvider(string $providerSlug): bool
    {
        return $this->chatProviders->contains('slug', $providerSlug);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
