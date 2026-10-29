<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A shopping list shared by the members of its workspace with the
 * `shopping` module (US-8.1). Archived lists (`archived_at`) leave the
 * active view but keep their items. At most one list per workspace is the
 * default (`is_default`, partial unique index on `workspace_id`): the one a
 * voice command means when it names no list — for a user in several
 * workspaces, the default of their default workspace (`defaultFor()`).
 *
 * A list made by a ShoppingListSchedule has the deadline of its run
 * (`due_at`); completing it (`completed_at`) also archives it, and the
 * schedule makes the next one.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $shopping_list_schedule_id
 * @property string $friendly_id
 * @property string $name
 * @property Carbon|null $archived_at
 * @property bool $is_default
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property int|null $completed_by_id
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $createdBy
 * @property-read User|null $completedBy
 * @property-read ShoppingListSchedule|null $schedule
 * @property-read int|null $items_count with `withItemCounts()`
 * @property-read int|null $unchecked_items_count with `withItemCounts()`
 */
#[Fillable(['workspace_id', 'shopping_list_schedule_id', 'name', 'archived_at', 'is_default', 'due_at', 'completed_at', 'completed_by_id', 'created_by_id'])]
class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
    use BelongsToWorkspace, HasFactory, HasFriendlyId, SoftDeletes;

    public static function idPrefix(): string
    {
        return 'shl';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'is_default' => 'boolean',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function workspaceModule(): string
    {
        return Module::SHOPPING;
    }

    /**
     * The workspace's default list, if one is set (archiving or deleting it
     * unsets it).
     */
    public static function defaultIn(Workspace|int $workspace): ?self
    {
        return self::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('is_default', true)
            ->notArchived()
            ->first();
    }

    /**
     * The list a user means when they name none: the default of their
     * default workspace for shopping or, when it has none, the only default
     * among the lists they see. Null when there is none, or several.
     */
    public static function defaultFor(User $user): ?self
    {
        $workspace = $user->defaultWorkspaceFor(Module::SHOPPING);

        if ($workspace === null) {
            return null;
        }

        $defaults = self::query()->visibleTo($user)->where('is_default', true)->notArchived()->get();

        return $defaults->firstWhere('workspace_id', $workspace->id)
            ?? ($defaults->count() === 1 ? $defaults->sole() : null);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull($this->qualifyColumn('archived_at'));
    }

    /**
     * How many items the list has, and how many are still to pick
     * (`items_count`, `unchecked_items_count`).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withItemCounts(Builder $query): void
    {
        $query->withCount([
            'items',
            'items as unchecked_items_count' => fn ($query) => $query->whereNull('checked_at'),
        ]);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * A scheduled list still open after its deadline.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && ! $this->isCompleted() && ! $this->isArchived() && $this->due_at->isPast();
    }

    /**
     * The items in display order: unchecked ones first, by `position`, and
     * the checked ones at the end, in the order they were checked (US-8.2).
     *
     * @return HasMany<ShoppingListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class)
            ->orderByRaw('checked_at asc nulls first')
            ->orderBy('position');
    }

    /**
     * The recurring list it belongs to, even once deleted.
     *
     * @return BelongsTo<ShoppingListSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ShoppingListSchedule::class, 'shopping_list_schedule_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
