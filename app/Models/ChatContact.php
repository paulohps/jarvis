<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ChatContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person as the user knows them across providers: the conversations
 * linked to a contact (a WhatsApp chat and a Slack DM of the same
 * colleague, say) show the link on the screen and open one another. The
 * user's own, never shared.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property string $name
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, ChatConversation> $conversations
 */
#[Fillable(['user_id', 'name', 'notes'])]
class ChatContact extends Model
{
    /** @use HasFactory<ChatContactFactory> */
    use HasFactory, HasFriendlyId;

    public const int MAX_NAME_LENGTH = 100;

    public static function idPrefix(): string
    {
        return 'cct';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ChatConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'chat_contact_id');
    }
}
