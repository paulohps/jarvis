<?php

namespace App\Models;

use App\Models\Concerns\HasFriendlyId;
use Database\Factories\SlackAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Slack workspace a user connected to Jarvis by OAuth, with the user
 * token (`xoxp-…`, SlackChatProvider::USER_SCOPES) Jarvis reads and writes
 * their conversations with — as them, never as a bot. Owned by one user;
 * a user may connect several workspaces. The token is stored encrypted;
 * when Slack stops accepting it (`token_revoked`, `account_inactive`) the
 * account keeps the error until it is connected again.
 *
 * @property int $id
 * @property string $friendly_id
 * @property int $user_id
 * @property string $team_id
 * @property string $team_name
 * @property string|null $team_domain
 * @property string $slack_user_id
 * @property string $access_token
 * @property list<string>|null $scopes
 * @property Carbon $connected_at
 * @property Carbon|null $synced_at
 * @property Carbon|null $failed_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, SlackConversation> $conversations
 * @property-read Collection<int, SlackUser> $members
 */
#[Fillable(['user_id', 'team_id', 'team_name', 'team_domain', 'slack_user_id', 'access_token', 'scopes', 'connected_at', 'synced_at', 'failed_at', 'last_error'])]
#[Hidden(['access_token'])]
class SlackAccount extends Model
{
    /** @use HasFactory<SlackAccountFactory> */
    use HasFactory, HasFriendlyId;

    public static function idPrefix(): string
    {
        return 'slk';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'synced_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function hasFailed(): bool
    {
        return $this->failed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->hasFailed();
    }

    /**
     * The URL of the workspace on Slack.
     */
    public function url(): ?string
    {
        return filled($this->team_domain) ? "https://{$this->team_domain}.slack.com" : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SlackConversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(SlackConversation::class);
    }

    /**
     * The people of the workspace, as far as they were synced.
     *
     * @return HasMany<SlackUser, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(SlackUser::class);
    }
}
