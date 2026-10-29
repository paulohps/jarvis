<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\WhatsappTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A tag of the user's own for WhatsApp chats and groups (e.g. "Clientes",
 * "Família"), with one of WhatsappTarget::PRIORITY_COLORS, to mark them and
 * filter the chat list by them. A tag goes on chats of any of the user's
 * numbers.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'name', 'color'])]
class WhatsappTag extends Model
{
    /** @use HasFactory<WhatsappTagFactory> */
    use HasFactory, HasFriendlyId;

    public const int MAX_NAME_LENGTH = 40;

    public static function idPrefix(): string
    {
        return 'wtag';
    }

    /**
     * The hex of the tag's color.
     */
    public function hex(): string
    {
        return WhatsappTarget::PRIORITY_COLORS[$this->color] ?? WhatsappTarget::PRIORITY_COLORS['gray'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<WhatsappTarget, $this>
     */
    public function targets(): BelongsToMany
    {
        return $this->belongsToMany(WhatsappTarget::class)->withTimestamps();
    }
}
