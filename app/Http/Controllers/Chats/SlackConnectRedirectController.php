<?php

namespace App\Http\Controllers\Chats;

use App\Http\Controllers\Controller;
use App\Models\ChatProvider;
use App\Models\User;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackChatProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Sends the user to Slack to let Jarvis act as them on one of their
 * workspaces (SlackChatProvider::USER_SCOPES). Slack's own OAuth v2 rather
 * than Socialite's `slack` driver: that one signs people in with the
 * `identity.*` scopes, which Slack does not let an app ask together with
 * the conversation scopes. The state kept in the session is checked on
 * the way back.
 *
 * The callback URL (`chats.slack.callback`) must be among the redirect
 * URLs of the Slack app. Only for users with Slack switched on.
 */
class SlackConnectRedirectController extends Controller
{
    public const string SESSION_KEY = 'chats.slack_connect_state';

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->hasChatProvider(ChatProvider::SLACK), 403, __('This chat channel is not switched on for you.'));

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, $state);

        return redirect()->away(SlackApiClient::authorizeUrl(SlackChatProvider::USER_SCOPES, route('chats.slack.callback'), $state));
    }
}
