<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\ChatScheduledDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sending of a scheduled message of the chat screen (ChatScheduledMessage),
 * sent or failed: what went (`body`, as the AI wrote it when it did) to which
 * conversation, for which occurrence (`slot_at`, one sending each), and why
 * it failed. Kept when the message or the conversation goes, with the
 * conversation's name as it was.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property int|null $chat_scheduled_message_id
 * @property int|null $chat_conversation_id
 * @property string|null $conversation_name
 * @property Carbon $slot_at
 * @property string|null $body
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read ChatScheduledMessage|null $scheduledMessage
 * @property-read ChatConversation|null $conversation
 */
#[Fillable(['user_id', 'chat_scheduled_message_id', 'chat_conversation_id', 'conversation_name', 'slot_at', 'body', 'status', 'error'])]
class ChatScheduledDelivery extends Model
{
    /** @use HasFactory<ChatScheduledDeliveryFactory> */
    use HasFactory, HasFriendlyId;

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const int MAX_ERROR_LENGTH = 500;

    public static function idPrefix(): string
    {
        return 'csd';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_at' => 'datetime',
        ];
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ChatScheduledMessage, $this>
     */
    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ChatScheduledMessage::class, 'chat_scheduled_message_id');
    }

    /**
     * @return BelongsTo<ChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }
}
