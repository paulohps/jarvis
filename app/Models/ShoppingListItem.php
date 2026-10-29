<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item of a shopping list. Checked when `checked_at` is set;
 * `client_updated_at` decides offline conflicts by last write (US-8.2).
 * It belongs to its list's workspace: whoever sees the list sees the item.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $shopping_list_id
 * @property string $name
 * @property string|null $quantity_note
 * @property int $position
 * @property Carbon|null $checked_at
 * @property int|null $checked_by_id
 * @property Carbon|null $client_updated_at
 * @property int $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ShoppingList $list
 * @property-read User|null $checkedBy
 * @property-read User $createdBy
 */
#[Fillable([
    'shopping_list_id', 'name', 'quantity_note', 'position', 'checked_at', 'checked_by_id', 'client_updated_at',
    'created_by_id',
])]
class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'shi';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'checked_at' => 'datetime',
            'client_updated_at' => 'datetime',
        ];
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
