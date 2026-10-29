<?php

namespace App\Models;

use Database\Factories\WhatsappChatReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * When a message the number sent reached someone, and when they read or
 * played it, for the message's info screen: the contact in a one-to-one
 * chat, each participant in a group. Each time is kept the first time
 * WhatsApp reports it.
 *
 * @property int $id
 * @property int $whatsapp_chat_message_id
 * @property string $participant the JID of who got it
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $played_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappChatMessage $message
 */
#[Fillable(['whatsapp_chat_message_id', 'participant', 'delivered_at', 'read_at', 'played_at'])]
class WhatsappChatReceipt extends Model
{
    /** @use HasFactory<WhatsappChatReceiptFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WhatsappChatMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsappChatMessage::class, 'whatsapp_chat_message_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'played_at' => 'datetime',
        ];
    }
}
