<?php

namespace App\Actions\Slack;

use App\Actions\Chats\AuthorizesChats;
use App\Jobs\SyncSlackAccount;
use App\Models\SlackAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * "Sync conversations" on a Slack workspace: queues SyncSlackAccount for
 * the owner's account.
 */
final readonly class ResyncSlackAccount
{
    use AuthorizesChats;

    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, SlackAccount $account): void
    {
        $this->authorizeChats($actor);

        if ($account->user_id !== $actor->id) {
            throw new AuthorizationException(__('This Slack workspace is not yours.'));
        }

        SyncSlackAccount::dispatch($account);
    }
}
