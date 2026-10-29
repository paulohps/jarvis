<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\WhatsappDraftConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A conversation of the user with the AI about what to write in a WhatsApp
 * chat (ContinueWhatsappDraftConversation): the user asks, the AI writes a
 * message in their style, the user asks for changes or edits it by hand,
 * and uses the one they like. Named after the first request
 * (NameWhatsappDraftConversation), kept to be continued later.
 *
 * `reads_chat` says whether the AI reads the chat's latest messages (else
 * it writes from the requests alone), and `reply_to` the friendly ids of
 * the messages it answers, when the user picked them.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property int $whatsapp_target_id
 * @property string|null $title
 * @property bool $reads_chat
 * @property list<string>|null $reply_to
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read WhatsappTarget $chat
 * @property-read Collection<int, WhatsappDraftMessage> $messages
 */
#[Fillable(['user_id', 'whatsapp_target_id', 'title', 'reads_chat', 'reply_to'])]
class WhatsappDraftConversation extends Model
{
    /** @use HasFactory<WhatsappDraftConversationFactory> */
    use HasFactory, HasFriendlyId;

    public const int MAX_TITLE_LENGTH = 120;

    public static function idPrefix(): string
    {
        return 'wdcv';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<WhatsappTarget, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsappTarget::class, 'whatsapp_target_id');
    }

    /**
     * Oldest first.
     *
     * @return HasMany<WhatsappDraftMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappDraftMessage::class)->orderBy('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reads_chat' => 'boolean',
            'reply_to' => 'array',
        ];
    }
}
