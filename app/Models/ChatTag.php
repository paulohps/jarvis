<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ChatTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A tag of the user's own for the conversations of the chat screen,
 * whatever their provider ("Clientes", "Família"…), with one of
 * WhatsappTarget::PRIORITY_COLORS, to mark them and filter the list.
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
class ChatTag extends Model
{
    /** @use HasFactory<ChatTagFactory> */
    use HasFactory, HasFriendlyId;

    public const int MAX_NAME_LENGTH = 40;

    public static function idPrefix(): string
    {
        return 'ctag';
    }

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
     * @return BelongsToMany<ChatConversation, $this>
     */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(ChatConversation::class, 'chat_conversation_tag')->withTimestamps();
    }
}
