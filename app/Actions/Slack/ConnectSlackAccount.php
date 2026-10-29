<?php

namespace App\Actions\Slack;

use App\Actions\Chats\AuthorizesChats;
use App\Jobs\SyncSlackAccount;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the user token Slack handed over for a workspace (Key Concept
 * "Chat channel"): one account per user and workspace, connected again
 * when it was there before (a revoked token gives way to the new one and
 * the failure is cleared), then synced in the background
 * (SyncSlackAccount). The same Slack user cannot be connected by two
 * Jarvis users.
 *
 * Only users with the `chats` module and Slack switched on for them.
 */
final readonly class ConnectSlackAccount
{
    use AuthorizesChats;

    /**
     * @param  array{team_id: string, team_name: string, team_domain?: string|null, user_id: string, access_token: string, scopes?: list<string>}  $identity
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $identity): SlackAccount
    {
        $this->authorizeProvider($actor, ChatProvider::SLACK);

        $takenBy = SlackAccount::query()
            ->where('team_id', $identity['team_id'])
            ->where('slack_user_id', $identity['user_id'])
            ->where('user_id', '!=', $actor->id)
            ->exists();

        if ($takenBy) {
            throw ValidationException::withMessages(['slack' => __('This Slack user is already connected by someone else.')]);
        }

        $account = SlackAccount::query()->updateOrCreate(
            ['user_id' => $actor->id, 'team_id' => $identity['team_id']],
            [
                'team_name' => mb_substr($identity['team_name'], 0, 100),
                'team_domain' => filled($identity['team_domain'] ?? null) ? mb_substr((string) $identity['team_domain'], 0, 100) : null,
                'slack_user_id' => $identity['user_id'],
                'access_token' => $identity['access_token'],
                'scopes' => $identity['scopes'] ?? null,
                'connected_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ],
        );

        SyncSlackAccount::dispatch($account);

        return $account;
    }
}
