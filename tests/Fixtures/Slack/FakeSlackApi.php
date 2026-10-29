<?php

namespace Tests\Fixtures\Slack;

use App\Services\Slack\SlackChatProvider;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the Slack Web API over `Http::fake()`, so no test reaches the
 * network: every method `SlackApiClient` calls answers like Slack does,
 * and any other request throws (`preventStrayRequests`).
 *
 * Pass `$overrides` by Web API method name (`auth.test`,
 * `conversations.history`, `chat.postMessage`… and `upload` for the
 * upload URL, `download` for `url_private`) to answer something else, an
 * error included (`['ok' => false, 'error' => 'channel_not_found']`).
 */
final class FakeSlackApi
{
    public const string TEAM_ID = 'T0123456789';

    public const string USER_ID = 'U0000000001';

    public const string ANA_ID = 'U0000000002';

    public const string BIA_ID = 'U0000000003';

    public const string DM_ID = 'D0000000001';

    public const string CHANNEL_ID = 'C0000000001';

    public const string TOKEN = 'xoxp-fake-token';

    public const string UPLOAD_URL = 'https://files.slack.com/upload/v1/fake';

    public const string FILE_URL = 'https://files.slack.com/files-pri/T0123456789-F0000000001/report.pdf';

    /**
     * @param  array<string, PromiseInterface|Closure|array<mixed>>  $overrides
     */
    public static function install(array $overrides = []): void
    {
        $responses = [...self::defaults(), ...$overrides];
        $fakes = [];

        foreach ($responses as $method => $response) {
            $url = match ($method) {
                'upload' => self::UPLOAD_URL,
                'download' => self::FILE_URL,
                default => 'https://slack.com/api/'.$method,
            };

            $fakes[$url] = is_array($response) ? Http::response($response) : $response;
        }

        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake($fakes);
    }

    /**
     * Whether a call to the method was made, with the form fields given.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function called(string $method, array $fields = []): bool
    {
        return Http::recorded(function (Request $request) use ($method, $fields): bool {
            if ($request->url() !== 'https://slack.com/api/'.$method) {
                return false;
            }

            foreach ($fields as $key => $value) {
                $sent = $request->data()[$key] ?? null;

                if (is_scalar($sent) && is_scalar($value) ? (string) $sent !== (string) $value : $sent !== $value) {
                    return false;
                }
            }

            return true;
        })->isNotEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    public static function member(string $id, string $name, string $realName): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'real_name' => $realName,
            'deleted' => false,
            'is_bot' => false,
            'profile' => ['display_name' => $realName, 'real_name' => $realName, 'image_72' => "https://avatars.slack-edge.com/{$id}_72.png"],
        ];
    }

    /**
     * A message object as the history and the events carry it.
     *
     * @return array<string, mixed>
     */
    public static function message(string $ts, string $user, string $text, array $extra = []): array
    {
        return ['type' => 'message', 'ts' => $ts, 'user' => $user, 'text' => $text, 'team' => self::TEAM_ID, ...$extra];
    }

    /**
     * @return array<string, PromiseInterface|Closure|array<mixed>>
     */
    private static function defaults(): array
    {
        return [
            'oauth.v2.access' => [
                'ok' => true,
                'app_id' => 'A0000000001',
                'authed_user' => ['id' => self::USER_ID, 'scope' => implode(',', SlackChatProvider::USER_SCOPES), 'access_token' => self::TOKEN, 'token_type' => 'user'],
                'team' => ['id' => self::TEAM_ID, 'name' => 'Acme'],
            ],
            'auth.test' => ['ok' => true, 'url' => 'https://acme.slack.com/', 'team' => 'Acme', 'user' => 'paulo', 'team_id' => self::TEAM_ID, 'user_id' => self::USER_ID],
            'auth.revoke' => ['ok' => true, 'revoked' => true],
            'users.list' => ['ok' => true, 'members' => [
                self::member(self::USER_ID, 'paulo', 'Jane Doe'),
                self::member(self::ANA_ID, 'ana', 'Ana Lima'),
                self::member(self::BIA_ID, 'bia', 'Bia Costa'),
            ], 'response_metadata' => ['next_cursor' => '']],
            'users.info' => fn (Request $request) => Http::response(match ($request->data()['user'] ?? null) {
                self::USER_ID => ['ok' => true, 'user' => self::member(self::USER_ID, 'paulo', 'Jane Doe')],
                self::ANA_ID => ['ok' => true, 'user' => self::member(self::ANA_ID, 'ana', 'Ana Lima')],
                self::BIA_ID => ['ok' => true, 'user' => self::member(self::BIA_ID, 'bia', 'Bia Costa')],
                default => ['ok' => false, 'error' => 'user_not_found'],
            }),
            'users.conversations' => ['ok' => true, 'channels' => [
                ['id' => self::CHANNEL_ID, 'name' => 'general', 'is_channel' => true, 'is_private' => false, 'is_member' => true, 'is_archived' => false, 'topic' => ['value' => 'Company wide']],
                ['id' => self::DM_ID, 'is_im' => true, 'user' => self::ANA_ID, 'is_user_deleted' => false],
            ], 'response_metadata' => ['next_cursor' => '']],
            'conversations.info' => ['ok' => true, 'channel' => ['id' => self::DM_ID, 'is_im' => true, 'user' => self::ANA_ID, 'last_read' => '1700000000.000100', 'unread_count_display' => 2]],
            'conversations.members' => ['ok' => true, 'members' => [self::USER_ID, self::ANA_ID, self::BIA_ID], 'response_metadata' => ['next_cursor' => '']],
            'conversations.open' => ['ok' => true, 'channel' => ['id' => self::DM_ID]],
            'conversations.history' => ['ok' => true, 'messages' => [
                self::message('1700000300.000200', self::ANA_ID, 'Hi <@'.self::USER_ID.'>, can you review the PR?'),
                self::message('1700000200.000100', self::USER_ID, 'Sure, sending the report'),
                self::message('1700000100.000100', self::ANA_ID, 'Morning :wave:', ['reactions' => [['name' => 'thumbsup', 'users' => [self::USER_ID], 'count' => 1]]]),
            ], 'has_more' => false],
            'conversations.replies' => ['ok' => true, 'messages' => [
                self::message('1700000300.000200', self::ANA_ID, 'Hi <@'.self::USER_ID.'>, can you review the PR?', ['thread_ts' => '1700000300.000200', 'reply_count' => 1]),
                self::message('1700000400.000100', self::USER_ID, 'On it', ['thread_ts' => '1700000300.000200']),
            ], 'has_more' => false, 'response_metadata' => ['next_cursor' => '']],
            'conversations.mark' => ['ok' => true],
            'chat.postMessage' => ['ok' => true, 'channel' => self::DM_ID, 'ts' => '1700000500.000100', 'message' => self::message('1700000500.000100', self::USER_ID, 'posted text')],
            'chat.update' => ['ok' => true, 'channel' => self::DM_ID, 'ts' => '1700000200.000100', 'text' => 'edited text', 'message' => ['text' => 'edited text', 'user' => self::USER_ID]],
            'chat.delete' => ['ok' => true, 'channel' => self::DM_ID, 'ts' => '1700000200.000100'],
            'reactions.add' => ['ok' => true],
            'reactions.remove' => ['ok' => true],
            'files.getUploadURLExternal' => ['ok' => true, 'upload_url' => self::UPLOAD_URL, 'file_id' => 'F0000000001'],
            'upload' => ['ok' => true],
            'files.completeUploadExternal' => ['ok' => true, 'files' => [['id' => 'F0000000001', 'title' => 'report.pdf']]],
            'download' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        ];
    }
}
