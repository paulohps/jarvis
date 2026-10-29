<?php

namespace App\Actions\Slack;

use App\Actions\Chats\AuthorizesChats;
use App\Models\ChatConversation;
use App\Models\SlackAccount;
use App\Models\User;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Removes a Slack workspace from the user's chat screen: the token is
 * revoked on Slack (best effort: a token already dead is fine) and the
 * account goes with its people, conversations and messages, and the
 * entries of the chat screen that pointed at them — their tags, reminders
 * links and scheduled messages included. The reminders themselves stay.
 *
 * Only the owner of the account.
 */
final readonly class DisconnectSlackAccount
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

        try {
            SlackApiClient::forAccount($account)->authRevoke();
        } catch (SlackApiException $exception) {
            if (! $exception->isTokenInvalid()) {
                report($exception);
            }
        }

        DB::transaction(function () use ($account): void {
            ChatConversation::query()
                ->where('conversable_type', 'slack_conversation')
                ->whereIn('conversable_id', $account->conversations()->select('id'))
                ->delete();

            $account->delete();
        });
    }
}
