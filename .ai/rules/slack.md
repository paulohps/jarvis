---
paths:
  - 'app/Services/Slack/**'
  - 'app/Actions/Slack/**'
  - 'app/Models/Slack*.php'
  - 'app/Jobs/*Slack*.php'
  - 'app/Http/Controllers/Webhooks/SlackWebhookController.php'
  - 'app/Http/Middleware/VerifySlackSignature.php'
  - 'app/Http/Controllers/Chats/SlackConnect*.php'
  - 'tests/Fixtures/Slack/**'
  - 'tests/Feature/Slack*.php'
---

# Slack (chat provider)

## Jarvis acts as the user, never as a bot
Each `SlackAccount` holds a user token (`xoxp-…`) for one workspace, encrypted (`access_token` cast, `#[Hidden]`). Messages go out as the user via `chat.postMessage`. A user may connect several workspaces, but the same Slack user cannot be connected by two Jarvis users (`ConnectSlackAccount`). `services.slack.notifications.bot_user_oauth_token` (`SLACK_BOT_USER_OAUTH_TOKEN`) is Laravel's stock notification config, and no chat code reads it.

## OAuth is Slack's own v2, not Socialite
`SlackConnectRedirectController` builds `oauth.v2.authorize` with `user_scope` = `SlackChatProvider::USER_SCOPES` and a session state. Socialite's `slack` driver asks for `identity.*` scopes, which Slack refuses together with the conversation scopes. The callback (`chats.slack.callback` = `/chats/slack/callback`, built with `route()`, so `SLACK_REDIRECT_URI` is not read) refuses unless every scope was granted.

## Adding a scope means everyone reconnects
`USER_SCOPES` is compared with the granted `authed_user.scope` only on connect, so existing tokens keep their old scopes. `missing_scope` is one of `SlackApiException::TOKEN_ERRORS`: the first call that needs the new scope marks the account failed (read-only) until the user connects again. Add the scope to the Slack app config too, and tell users to reconnect.

## Events: verify, dedupe, queue, answer fast
`/api/v1/webhooks/slack` goes through `VerifySlackSignature` (HMAC `v0:{ts}:{raw body}`, 5-minute tolerance, 401 without secret). `SlackWebhookController` answers `url_verification`, drops a repeated `event_id` (cache 5 min) and queues `ProcessSlackEvent` (queue `chats`, 3 tries). Slack wants the 200 within 3 seconds, so do no Slack API call in the request.

## One event, every account of the team
Slack sends an event once per app, so `HandleSlackEvent` applies it to every `SlackAccount` with that `team_id`. An account that does not know the channel asks `conversations.info` and keeps it only when the user is a member (a DM always). To add an event type, add a `match` arm there, subscribe it in the Slack app, and add a case to `tests/Feature/SlackEventsTest.php`.

## Messages keyed by (conversation, ts)
`RecordSlackMessages` upserts by `ts`, so history and events can overlap. `$live` (events only) bumps the unread count, resets it on the user's own message, broadcasts `ChatConversationUpdated` and pushes `ChatMessageReceived` unless muted. History loads only refresh the last message. Thread replies stay out of `messages()` unless broadcast to the channel.

## History is rate limited (Tier 1)
For apps outside the Marketplace, `conversations.history` allows about one call a minute. `LoadSlackConversationHistory` releases itself for `SlackApiException::$retryAfter`, so a big workspace fills slowly while events keep it live. Don't add loops of history calls in a request. `SyncSlackAccount` (unique per account, 110s) syncs `auth.test`, `users.list` and `users.conversations`, then queues history DMs first.

## Dead tokens make the account read-only, not deleted
`token_revoked`, `account_inactive`, `tokens_revoked` and `app_uninstalled` set the account's error. Its conversations become read-only (`capabilities()->readOnly()`) until it is connected again, and reconnecting clears the error. Only `DisconnectSlackAccount` deletes the account and its conversations, messages and entries (reminders stay).

## mrkdwn in and out
Texts are stored as Slack sends them (`&`, `<`, `>` escaped, `<@U…>` tokens). Render with `SlackMessageFormat::toHtml()`, preview/AI with `toPlain()`, and prepare outgoing text with `outgoing()` (escapes, `@handle` → `<@U…>`). Never echo raw Slack text in Blade.

## Reactions use names, the UI uses characters
`SlackEmoji` maps `:name:` ↔ character. Only its characters are offered (`reactionEmojis`). An unknown name shows as `:name:`. Send names to `reactions.add/remove`.

## Files go through the driver and signed URLs
Slack files (`url_private`) need the token, so `SlackChatProvider::file()` downloads them (max `MAX_FILE_BYTES` 25 MB, else link to Slack) and the screens get a signed `api.chats.file` URL. Uploads use the external upload flow (`files.getUploadURLExternal` → upload → complete).

## People come from SlackUserDirectory
Names and pictures come from `slack_users` (filled by `users.list`), with a single `users.info` fallback per unknown id. Don't call `users.info` per message.

## Tests: FakeSlackApi
`FakeSlackApi::install([...overrides by Web API method, plus 'upload' and 'download'])` fakes every method with `preventStrayRequests()`. Use `alongside()` when `FakeEvolutionApi::install()` already ran (install would wipe its stubs). Signed webhook requests: see `tests/Feature/SlackWebhookTest.php`. Constants `FakeSlackApi::TEAM_ID`, `USER_ID`, `DM_ID`, `CHANNEL_ID` match the factories.
