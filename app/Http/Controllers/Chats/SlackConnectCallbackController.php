<?php

namespace App\Http\Controllers\Chats;

use App\Actions\Slack\ConnectSlackAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Slack\SlackApiClient;
use App\Services\Slack\SlackApiException;
use App\Services\Slack\SlackChatProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Where Slack sends the user back after allowing (or not) Jarvis to act
 * as them: the code is traded for the user token, the workspace kept
 * (ConnectSlackAccount) and the user goes back to the channels screen
 * with what happened. Every scope asked must have been granted.
 */
class SlackConnectCallbackController extends Controller
{
    public const string STATUS_KEY = 'chats.status';

    public const string ERROR_KEY = 'chats.error';

    public function __invoke(Request $request, ConnectSlackAccount $connect): RedirectResponse
    {
        $state = $request->session()->pull(SlackConnectRedirectController::SESSION_KEY);

        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->query('state', ''))) {
            return $this->refuse(__('The Slack workspace was not connected.'));
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->refuse(__('The Slack workspace was not connected.'));
        }

        try {
            $granted = SlackApiClient::exchangeCode((string) $request->query('code'), route('chats.slack.callback'));
            $token = (string) ($granted['authed_user']['access_token'] ?? '');

            if ($token === '') {
                return $this->refuse(__('Slack did not hand over a user token. Connect again.'));
            }

            $scopes = array_values(array_filter(explode(',', (string) ($granted['authed_user']['scope'] ?? ''))));

            if (array_diff(SlackChatProvider::USER_SCOPES, $scopes) !== []) {
                return $this->refuse(__('Slack did not grant every permission Jarvis needs. Connect again and allow them all.'));
            }

            $identity = SlackApiClient::withToken($token)->authTest();
        } catch (SlackApiException $exception) {
            report($exception);

            return $this->refuse(__('Slack could not be reached. Try again.'));
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $account = $connect($user, [
                'team_id' => (string) ($granted['team']['id'] ?? $identity['team_id'] ?? ''),
                'team_name' => (string) ($granted['team']['name'] ?? $identity['team'] ?? ''),
                'team_domain' => $this->domainOf($identity['url'] ?? null),
                'user_id' => (string) ($granted['authed_user']['id'] ?? $identity['user_id'] ?? ''),
                'access_token' => $token,
                'scopes' => $scopes,
            ]);
        } catch (ValidationException $exception) {
            return $this->refuse((string) collect($exception->errors())->flatten()->first());
        } catch (AuthorizationException $exception) {
            return $this->refuse($exception->getMessage());
        }

        return redirect()->route('chats.index')
            ->with(self::STATUS_KEY, __(':workspace is connected. Its conversations are on their way.', ['workspace' => $account->team_name]));
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()->route('chats.index')->with(self::ERROR_KEY, $message);
    }

    private function domainOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_ends_with($host, '.slack.com') ? substr($host, 0, -10) : null;
    }
}
