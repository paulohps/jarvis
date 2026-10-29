<?php

namespace App\Services\Slack;

use App\Models\SlackAccount;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Slack Web API with one token (a user's `xoxp-…`): every call is a
 * form POST to `https://slack.com/api/<method>` with the token as a Bearer,
 * answered as JSON with `ok`. Methods used, checked against the reference
 * (docs.slack.dev/reference/methods):
 *
 * - `auth.test`, `auth.revoke`;
 * - `users.conversations` (the conversations the user is in, every type),
 *   `conversations.info`, `conversations.members`, `conversations.open`;
 * - `conversations.history`, `conversations.replies` (Tier 1 — 1 per
 *   minute, 15 objects — for apps distributed outside the Marketplace;
 *   Tier 3 for internal apps like this one), `conversations.mark`;
 * - `users.list`, `users.info`;
 * - `chat.postMessage` (`thread_ts` to answer in a thread), `chat.update`,
 *   `chat.delete`;
 * - `reactions.add`, `reactions.remove`;
 * - `files.getUploadURLExternal` + the upload + `files.completeUploadExternal`
 *   (`files.upload` is retired), and the file itself from `url_private`.
 *
 * Every call times out after `services.slack.timeout` seconds; `ok: false`,
 * a 429 or no answer throws SlackApiException (a 429 carries `Retry-After`).
 * Tests fake it with `FakeSlackApi`.
 */
final readonly class SlackApiClient
{
    public const string BASE_URL = 'https://slack.com/api';

    /**
     * The conversation types `users.conversations` is asked for.
     */
    public const string CONVERSATION_TYPES = 'public_channel,private_channel,mpim,im';

    public function __construct(
        private string $token,
        private int $timeout = 10,
    ) {}

    public static function forAccount(SlackAccount $account): self
    {
        return new self($account->access_token, (int) config('services.slack.timeout', 10));
    }

    public static function withToken(string $token): self
    {
        return new self($token, (int) config('services.slack.timeout', 10));
    }

    /**
     * Where to send the user to let the app act as them on a workspace
     * (OAuth v2, user scopes): `$state` comes back on the redirect URI.
     *
     * @param  list<string>  $userScopes
     */
    public static function authorizeUrl(array $userScopes, string $redirectUri, string $state): string
    {
        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => (string) config('services.slack.client_id'),
            'scope' => '',
            'user_scope' => implode(',', $userScopes),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Trades the code of the redirect for the user token
     * (`oauth.v2.access`): `authed_user` (`id`, `access_token`, `scope`)
     * and `team` (`id`, `name`).
     *
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    public static function exchangeCode(string $code, string $redirectUri): array
    {
        return (new self('', (int) config('services.slack.timeout', 10)))->call('oauth.v2.access', [
            'client_id' => (string) config('services.slack.client_id'),
            'client_secret' => (string) config('services.slack.client_secret'),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Who the token is: `user_id`, `team_id`, `team`, `url`, `user`.
     *
     * @return array<string, mixed>
     */
    public function authTest(): array
    {
        return $this->call('auth.test');
    }

    public function authRevoke(): void
    {
        $this->call('auth.revoke');
    }

    /**
     * The conversations the user is in, page by page: channel objects.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function userConversations(bool $excludeArchived = true): Generator
    {
        yield from $this->paginate('users.conversations', [
            'types' => self::CONVERSATION_TYPES,
            'exclude_archived' => $excludeArchived ? 'true' : 'false',
            'limit' => 200,
        ], 'channels');
    }

    /**
     * @return array<string, mixed> the channel object
     */
    public function conversationInfo(string $channelId): array
    {
        /** @var array<string, mixed> $channel */
        $channel = $this->call('conversations.info', ['channel' => $channelId])['channel'] ?? [];

        return $channel;
    }

    /**
     * The ids of the members of a conversation.
     *
     * @return list<string>
     */
    public function conversationMembers(string $channelId): array
    {
        $members = [];

        foreach ($this->paginate('conversations.members', ['channel' => $channelId, 'limit' => 500], 'members') as $member) {
            $members[] = (string) $member;
        }

        return $members;
    }

    /**
     * Opens (or finds) the DM with the person.
     *
     * @return array<string, mixed> the channel object, at least its `id`
     */
    public function openDirect(string $userId): array
    {
        /** @var array<string, mixed> $channel */
        $channel = $this->call('conversations.open', ['users' => $userId, 'return_im' => 'true'])['channel'] ?? [];

        return $channel;
    }

    /**
     * The latest messages of a conversation, newest first, or the ones
     * before `$latest` (exclusive).
     *
     * @return array{messages: list<array<string, mixed>>, has_more: bool}
     */
    public function history(string $channelId, ?string $latest = null, int $limit = 50): array
    {
        $response = $this->call('conversations.history', array_filter([
            'channel' => $channelId,
            'latest' => $latest,
            'inclusive' => 'false',
            'limit' => $limit,
        ], fn (mixed $value): bool => $value !== null));

        return [
            'messages' => array_values((array) ($response['messages'] ?? [])),
            'has_more' => (bool) ($response['has_more'] ?? false),
        ];
    }

    /**
     * A message and the replies of its thread, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function replies(string $channelId, string $threadTs, int $limit = 200): array
    {
        $messages = [];

        foreach ($this->paginate('conversations.replies', ['channel' => $channelId, 'ts' => $threadTs, 'limit' => $limit], 'messages') as $message) {
            $messages[] = (array) $message;
        }

        return $messages;
    }

    public function markRead(string $channelId, string $ts): void
    {
        $this->call('conversations.mark', ['channel' => $channelId, 'ts' => $ts]);
    }

    /**
     * Everyone in the workspace, page by page: member objects.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function users(): Generator
    {
        yield from $this->paginate('users.list', ['limit' => 200], 'members');
    }

    /**
     * @return array<string, mixed> the member object
     */
    public function userInfo(string $userId): array
    {
        /** @var array<string, mixed> $user */
        $user = $this->call('users.info', ['user' => $userId])['user'] ?? [];

        return $user;
    }

    /**
     * Posts a text as the user, in the thread of `$threadTs` when given.
     *
     * @return array<string, mixed> the message object, with its `ts`
     */
    public function postMessage(string $channelId, string $text, ?string $threadTs = null): array
    {
        $response = $this->call('chat.postMessage', array_filter([
            'channel' => $channelId,
            'text' => $text,
            'thread_ts' => $threadTs,
            'unfurl_links' => 'true',
        ], fn (mixed $value): bool => $value !== null));

        /** @var array<string, mixed> $message */
        $message = (array) ($response['message'] ?? []);
        $message['ts'] ??= $response['ts'] ?? null;
        $message['channel'] = $response['channel'] ?? $channelId;

        return $message;
    }

    /**
     * @return array<string, mixed> the message object as updated
     */
    public function updateMessage(string $channelId, string $ts, string $text): array
    {
        /** @var array<string, mixed> $message */
        $message = (array) ($this->call('chat.update', ['channel' => $channelId, 'ts' => $ts, 'text' => $text])['message'] ?? []);
        $message['ts'] ??= $ts;

        return $message;
    }

    public function deleteMessage(string $channelId, string $ts): void
    {
        $this->call('chat.delete', ['channel' => $channelId, 'ts' => $ts]);
    }

    /**
     * Adds the reaction (`$name` is Slack's emoji name, `thumbsup`); one
     * already there is fine.
     */
    public function addReaction(string $channelId, string $ts, string $name): void
    {
        try {
            $this->call('reactions.add', ['channel' => $channelId, 'timestamp' => $ts, 'name' => $name]);
        } catch (SlackApiException $exception) {
            if ($exception->error !== 'already_reacted') {
                throw $exception;
            }
        }
    }

    public function removeReaction(string $channelId, string $ts, string $name): void
    {
        try {
            $this->call('reactions.remove', ['channel' => $channelId, 'timestamp' => $ts, 'name' => $name]);
        } catch (SlackApiException $exception) {
            if ($exception->error !== 'no_reaction') {
                throw $exception;
            }
        }
    }

    /**
     * Uploads a file and shares it in the conversation (in the thread of
     * `$threadTs` when given) with `$comment` as its text, through
     * `files.getUploadURLExternal`, the upload and
     * `files.completeUploadExternal`.
     *
     * @return array<string, mixed> the file object Slack keeps
     *
     * @throws SlackApiException
     */
    public function uploadFile(string $channelId, string $contents, string $name, ?string $threadTs = null, ?string $comment = null): array
    {
        $ticket = $this->call('files.getUploadURLExternal', ['filename' => $name, 'length' => strlen($contents)]);

        try {
            $upload = $this->request()->withBody($contents, 'application/octet-stream')->post((string) $ticket['upload_url']);
        } catch (ConnectionException $exception) {
            throw SlackApiException::unreachable('files upload', $exception);
        }

        if ($upload->failed()) {
            throw new SlackApiException('Slack files upload failed with status '.$upload->status().'.', 'upload_failed', $upload->status());
        }

        $response = $this->call('files.completeUploadExternal', array_filter([
            'files' => json_encode([['id' => $ticket['file_id'], 'title' => $name]], JSON_THROW_ON_ERROR),
            'channel_id' => $channelId,
            'thread_ts' => $threadTs,
            'initial_comment' => $comment,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));

        /** @var array<string, mixed> $file */
        $file = (array) (($response['files'] ?? [])[0] ?? ['id' => $ticket['file_id']]);

        return $file;
    }

    /**
     * The bytes of a file (`url_private` / `url_private_download`).
     *
     * @throws SlackApiException
     */
    public function downloadFile(string $url): Response
    {
        try {
            $response = $this->request()->get($url);
        } catch (ConnectionException $exception) {
            throw SlackApiException::unreachable('file download', $exception);
        }

        if ($response->failed()) {
            throw new SlackApiException('Slack file download failed with status '.$response->status().'.', 'download_failed', $response->status());
        }

        return $response;
    }

    /**
     * One Web API call.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws SlackApiException
     */
    public function call(string $method, array $params = []): array
    {
        try {
            $response = $this->request()->asForm()->post(self::BASE_URL.'/'.$method, $params);
        } catch (ConnectionException $exception) {
            throw SlackApiException::unreachable($method, $exception);
        }

        if ($response->failed() || $response->json('ok') !== true) {
            throw SlackApiException::fromResponse($method, $response);
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    /**
     * The items of a paginated method, page by page.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, mixed>
     */
    private function paginate(string $method, array $params, string $key): Generator
    {
        $cursor = null;

        do {
            $response = $this->call($method, $cursor === null ? $params : [...$params, 'cursor' => $cursor]);

            foreach ((array) ($response[$key] ?? []) as $item) {
                yield $item;
            }

            $cursor = $response['response_metadata']['next_cursor'] ?? null;
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        } while ($cursor !== null);
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout)->acceptJson();

        return $this->token === '' ? $request : $request->withToken($this->token);
    }
}
