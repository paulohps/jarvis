<?php

namespace App\Models;

use Database\Factories\WhatsappPollVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What someone voted for in a poll of a chat, as WhatsApp tells it (a
 * `pollUpdateMessage`), the owner's included (voted on the phone: the
 * Evolution API has no way to vote).
 * One per person and poll; a vote with no option takes it back.
 *
 * @property int $id
 * @property int $whatsapp_chat_message_id
 * @property string $voter the JID of who voted, or ME for the owner of the number
 * @property list<string> $options the names of the options picked
 * @property Carbon $voted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WhatsappChatMessage $message
 */
#[Fillable(['whatsapp_chat_message_id', 'voter', 'options', 'voted_at'])]
class WhatsappPollVote extends Model
{
    /** @use HasFactory<WhatsappPollVoteFactory> */
    use HasFactory;

    /** The `voter` of the owner of the number. */
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
        return $this->voter === self::ME;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'voted_at' => 'datetime',
        ];
    }
}
