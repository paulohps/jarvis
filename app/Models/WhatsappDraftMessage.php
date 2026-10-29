<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\WhatsappDraftMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One turn of a conversation with the AI about a WhatsApp chat: what the
 * user asked (USER) or the message the AI wrote (ASSISTANT), the latter as
 * the user left it when they edited it by hand (`edited_at`), which is what
 * the AI sees from then on. `whatsapp_delivery_id` is the delivery the
 * user sent it with, which leads the sent message back here.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $whatsapp_draft_conversation_id
 * @property string $role
 * @property string $body
 * @property Carbon|null $edited_at
 * @property int|null $whatsapp_delivery_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappDraftConversation $conversation
 */
#[Fillable(['whatsapp_draft_conversation_id', 'role', 'body', 'edited_at', 'whatsapp_delivery_id'])]
class WhatsappDraftMessage extends Model
{
    /** @use HasFactory<WhatsappDraftMessageFactory> */
    use HasFactory, HasFriendlyId;

    public const string USER = 'user';

    public const string ASSISTANT = 'assistant';

    public static function idPrefix(): string
    {
        return 'wdms';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === self::ASSISTANT;
    }

    /**
     * @return BelongsTo<WhatsappDraftConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappDraftConversation::class, 'whatsapp_draft_conversation_id');
    }

    /**
     * @return BelongsTo<WhatsappDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WhatsappDelivery::class, 'whatsapp_delivery_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }
}
