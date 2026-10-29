<?php

namespace App\Models;

use Database\Factories\SlackUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person (or bot) of a connected Slack workspace, as `users.list` and
 * `user_change` report them: their handle, names and picture, to show who
 * sent each message and to name the DMs. Kept per account.
 *
 * @property int $id
 * @property int $slack_account_id
 * @property string $slack_user_id
 * @property string|null $name
 * @property string|null $real_name
 * @property string|null $display_name
 * @property string|null $picture_url
 * @property bool $is_bot
 * @property bool $is_deleted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SlackAccount $account
 */
#[Fillable(['slack_account_id', 'slack_user_id', 'name', 'real_name', 'display_name', 'picture_url', 'is_bot', 'is_deleted'])]
class SlackUser extends Model
{
    /** @use HasFactory<SlackUserFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    /**
     * The name shown, as Slack does: the display name, the real name, the
     * handle, the id.
     */
    public function displayName(): string
    {
        foreach ([$this->display_name, $this->real_name, $this->name] as $candidate) {
            if (filled($candidate)) {
                return (string) $candidate;
            }
        }

        return $this->slack_user_id;
    }

    /**
     * The attributes of a `users.list` / `users.info` member object.
     *
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    public static function attributesFrom(array $member): array
    {
        $profile = is_array($member['profile'] ?? null) ? $member['profile'] : [];

        return [
            'name' => isset($member['name']) ? mb_substr((string) $member['name'], 0, 100) : null,
            'real_name' => filled($profile['real_name'] ?? $member['real_name'] ?? null) ? mb_substr((string) ($profile['real_name'] ?? $member['real_name']), 0, 200) : null,
            'display_name' => filled($profile['display_name'] ?? null) ? mb_substr((string) $profile['display_name'], 0, 200) : null,
            'picture_url' => $profile['image_192'] ?? $profile['image_72'] ?? $profile['image_48'] ?? null,
            'is_bot' => (bool) ($member['is_bot'] ?? false) || ($member['id'] ?? '') === 'USLACKBOT',
            'is_deleted' => (bool) ($member['deleted'] ?? false),
        ];
    }

    /**
     * @return BelongsTo<SlackAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SlackAccount::class, 'slack_account_id');
    }
}
