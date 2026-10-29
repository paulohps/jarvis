<?php

namespace App\Models;

use Database\Factories\WhatsappChatReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The emoji someone reacted to a chat message with, as WhatsApp tells it
 * (a `reactionMessage`), or the owner reacted with from Jarvis or the
 * phone. One per person and message; an empty reaction removes it.
 *
 * @property int $id
 * @property int $whatsapp_chat_message_id
 * @property string $reactor the JID of who reacted, or ME for the owner of the number
 * @property string $emoji
 * @property Carbon $reacted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappChatMessage $message
 */
#[Fillable(['whatsapp_chat_message_id', 'reactor', 'emoji', 'reacted_at'])]
class WhatsappChatReaction extends Model
{
    /** @use HasFactory<WhatsappChatReactionFactory> */
    use HasFactory;

    /** The `reactor` of the owner of the number. */
    public const string ME = 'me';

    /**
     * @return BelongsTo<WhatsappChatMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappChatMessage::class, 'whatsapp_chat_message_id');
    }

    public function isMine(): bool
    {
        return $this->reactor === self::ME;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reacted_at' => 'datetime',
        ];
    }
}
