<?php

namespace App\Models;

use Database\Factories\SlackConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * A conversation of a connected Slack workspace the user is in: a DM
 * (`im`), a group DM (`mpim`), a public or a private channel. Its entry on
 * the chat screen is the ChatConversation that points at it
 * (`conversation`); the messages are the SlackMessages.
 *
 * `last_read` is the `ts` Slack says the user read up to, mirrored both
 * ways (`conversations.mark`). `history_loaded_at` says the latest
 * messages were fetched once: before that the screen shows only what the
 * events brought and offers to load them.
 *
 * @property int $id
 * @property int $slack_account_id
 * @property string $channel_id
 * @property string $kind
 * @property string|null $name
 * @property string|null $topic
 * @property string|null $counterpart_user_id
 * @property bool $is_archived
 * @property bool $is_member
 * @property string|null $last_read
 * @property Carbon|null $history_loaded_at
 * @property Carbon|null $history_exhausted_at the oldest message is in: Slack has nothing before it
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SlackAccount $account
 * @property-read ChatConversation|null $conversation
 * @property-read SlackUser|null $counterpart
 * @property-read Collection<int, SlackMessage> $messages
 */
#[Fillable(['slack_account_id', 'channel_id', 'kind', 'name', 'topic', 'counterpart_user_id', 'is_archived', 'is_member', 'last_read', 'history_loaded_at', 'history_exhausted_at'])]
class SlackConversation extends Model
{
    /** @use HasFactory<SlackConversationFactory> */
    use HasFactory;

    public const string KIND_IM = 'im';

    public const string KIND_MPIM = 'mpim';

    public const string KIND_PUBLIC = 'public_channel';

    public const string KIND_PRIVATE = 'private_channel';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'is_member' => 'boolean',
            'history_loaded_at' => 'datetime',
            'history_exhausted_at' => 'datetime',
        ];
    }

    public function isDirect(): bool
    {
        return $this->kind === self::KIND_IM;
    }

    public function isChannel(): bool
    {
        return in_array($this->kind, [self::KIND_PUBLIC, self::KIND_PRIVATE], true);
    }

    /**
     * The kind of the chat layer: a DM is `direct`, a group DM `group`, a
     * channel `channel`.
     */
    public function chatKind(): string
    {
        return match ($this->kind) {
            self::KIND_IM => ChatConversation::KIND_DIRECT,
            self::KIND_MPIM => ChatConversation::KIND_GROUP,
            default => ChatConversation::KIND_CHANNEL,
        };
    }

    /**
     * The kind of a `conversations.info` / `users.conversations` object.
     *
     * @param  array<string, mixed>  $channel
     */
    public static function kindOf(array $channel): string
    {
        return match (true) {
            (bool) ($channel['is_im'] ?? false) => self::KIND_IM,
            (bool) ($channel['is_mpim'] ?? false) => self::KIND_MPIM,
            (bool) ($channel['is_private'] ?? false) => self::KIND_PRIVATE,
            default => self::KIND_PUBLIC,
        };
    }

    /**
     * The link to the conversation on Slack.
     */
    public function url(): ?string
    {
        $base = $this->account->url();

        return $base === null ? null : "{$base}/archives/{$this->channel_id}";
    }

    /**
     * @return BelongsTo<SlackAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SlackAccount::class, 'slack_account_id');
    }

    /**
     * @return MorphOne<ChatConversation, $this>
     */
    public function conversation(): MorphOne
    {
        return $this->morphOne(ChatConversation::class, 'conversable');
    }

    /**
     * The other person of a DM.
     *
     * @return BelongsTo<SlackUser, $this>
     */
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(SlackUser::class, 'counterpart_user_id', 'slack_user_id')
            ->where('slack_users.slack_account_id', $this->slack_account_id);
    }

    /**
     * @return HasMany<SlackMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SlackMessage::class);
    }
}
