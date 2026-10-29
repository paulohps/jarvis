<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Carbon\CarbonImmutable;
use Database\Factories\SlackMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message of a Slack conversation, as the Events API and the history
 * report it. `ts` is Slack's id of the message in its conversation;
 * `thread_ts` is set on a reply, pointing at the parent. The parent keeps
 * `reply_count`. A deleted message keeps its row with `deleted_at`, so the
 * screen can say so. `files` holds the file objects Slack attached
 * (id, name, mimetype, size, url_private, thumb_360…); `reactions` the
 * `[{name, users}]` list.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $slack_conversation_id
 * @property string $ts
 * @property string|null $thread_ts
 * @property string|null $slack_user_id
 * @property string|null $bot_id
 * @property string|null $subtype
 * @property string|null $text
 * @property list<array<string, mixed>>|null $files
 * @property list<array{name: string, users: list<string>}>|null $reactions
 * @property int $reply_count
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SlackConversation $conversation
 */
#[Fillable(['slack_conversation_id', 'ts', 'thread_ts', 'slack_user_id', 'bot_id', 'subtype', 'text', 'files', 'reactions', 'reply_count', 'edited_at', 'deleted_at', 'sent_at'])]
class SlackMessage extends Model
{
    /** @use HasFactory<SlackMessageFactory> */
    use HasFactory, HasFriendlyId;

    /**
     * The subtypes that are messages of someone, not system notices.
     */
    public const array MESSAGE_SUBTYPES = [null, '', 'file_share', 'thread_broadcast', 'me_message', 'bot_message'];

    public static function idPrefix(): string
    {
        return 'slm';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
            'reactions' => 'array',
            'reply_count' => 'integer',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The instant of a Slack `ts` ("1700000000.123456").
     */
    public static function instantOf(string $ts): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC((int) explode('.', $ts, 2)[0]);
    }

    public function isReply(): bool
    {
        return $this->thread_ts !== null && $this->thread_ts !== $this->ts;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * A system notice (someone joined, the topic changed…), not a message.
     */
    public function isSystem(): bool
    {
        return ! in_array($this->subtype, self::MESSAGE_SUBTYPES, true);
    }

    public function isFromAccountUser(): bool
    {
        return $this->slack_user_id !== null && $this->slack_user_id === $this->conversation->account->slack_user_id;
    }

    /**
     * The messages of the conversation itself, not the replies of its
     * threads (a reply broadcast to the conversation counts as one of it).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function topLevel(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('thread_ts')
                ->orWhereColumn('thread_ts', 'ts')
                ->orWhere('subtype', 'thread_broadcast');
        });
    }

    /**
     * @return BelongsTo<SlackConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SlackConversation::class, 'slack_conversation_id');
    }
}
