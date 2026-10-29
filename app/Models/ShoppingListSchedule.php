<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasFriendlyId;
use App\Models\Concerns\HasRecurrence;
use App\Models\Contracts\Schedulable;
use App\Services\Shopping\ShoppingListScheduler;
use Carbon\CarbonImmutable;
use Database\Factories\ShoppingListScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A recurring shopping list of a workspace ("the market, every Friday"):
 * each run of its recurrence is the deadline of one list (`due_at`). Its
 * list is open until someone completes it or, when it
 * `closes_automatically`, until its deadline; then the next one is made for
 * the following run, with the `next_list_content`. A list closed by hand
 * only is left open past its deadline, and the next one waits for it when
 * the schedule `waits_for_close`. ShoppingListScheduler does the work; the
 * lists it makes belong to the schedule's workspace.
 *
 * Registered in the morph map as a `recurrences.schedulable` owner.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $friendly_id
 * @property string $name
 * @property ShoppingListNextContent $next_list_content
 * @property bool $closes_automatically
 * @property bool $waits_for_close
 * @property list<string>|null $template_items
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $createdBy
 */
#[Fillable(['workspace_id', 'name', 'next_list_content', 'closes_automatically', 'waits_for_close', 'template_items', 'created_by_id'])]
class ShoppingListSchedule extends Model implements Schedulable
{
    /** @use HasFactory<ShoppingListScheduleFactory> */
    use BelongsToWorkspace, HasFactory, HasFriendlyId, HasRecurrence, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'next_list_content' => 'pending',
        'closes_automatically' => true,
        'waits_for_close' => false,
    ];

    public static function idPrefix(): string
    {
        return 'sls';
    }

    public static function workspaceModule(): string
    {
        return Module::SHOPPING;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_list_content' => ShoppingListNextContent::class,
            'closes_automatically' => 'boolean',
            'waits_for_close' => 'boolean',
            'template_items' => 'array',
        ];
    }

    /**
     * A deadline is reached: its list closes, when it closes by itself, and
     * the next one is made.
     */
    public function runScheduledSlot(CarbonImmutable $slot): void
    {
        if ($this->trashed()) {
            return;
        }

        app(ShoppingListScheduler::class)->reachDeadline($this, $slot->utc());
    }

    public function ownerTimezone(): string
    {
        return $this->createdBy->timezone;
    }

    public function scheduledSlotQueue(): string
    {
        return 'default';
    }

    /**
     * The lists it made, the latest deadline first.
     *
     * @return HasMany<ShoppingList, $this>
     */
    public function lists(): HasMany
    {
        return $this->hasMany(ShoppingList::class)->orderByDesc('due_at')->orderByDesc('id');
    }

    /**
     * The list still open, if any: neither completed nor archived.
     */
    public function openList(): ?ShoppingList
    {
        return $this->lists()->whereNull('completed_at')->notArchived()->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
