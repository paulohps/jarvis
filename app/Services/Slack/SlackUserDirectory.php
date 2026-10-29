<?php

namespace App\Services\Slack;

use App\Models\SlackAccount;
use App\Models\SlackUser;
use Illuminate\Support\Collection;

/**
 * The people of a connected Slack workspace, by id, for the names and
 * pictures beside the messages and in the DMs: read from `slack_users`,
 * and fetched from Slack (`users.info`) once for the ones not synced yet.
 */
final class SlackUserDirectory
{
    /**
     * @var array<int, array<string, SlackUser>> account id => user id => row
     */
    private array $loaded = [];

    /**
     * The rows of the ids, fetching the unknown ones from Slack when a
     * client is given.
     *
     * @param  list<string>  $userIds
     * @return Collection<string, SlackUser>
     */
    public function usersOf(SlackAccount $account, array $userIds, ?SlackApiClient $client = null): Collection
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        $this->loaded[$account->id] ??= [];
        $cache = &$this->loaded[$account->id];

        $missing = array_values(array_filter($userIds, fn (string $id): bool => ! isset($cache[$id])));

        if ($missing !== []) {
            foreach (SlackUser::query()->where('slack_account_id', $account->id)->whereIn('slack_user_id', $missing)->get() as $user) {
                $cache[$user->slack_user_id] = $user;
            }
        }

        if ($client !== null) {
            foreach ($userIds as $id) {
                if (isset($cache[$id])) {
                    continue;
                }

                $fetched = $this->fetch($account, $id, $client);

                if ($fetched !== null) {
                    $cache[$id] = $fetched;
                }
            }
        }

        return (new Collection($cache))->only($userIds);
    }

    /**
     * Id => name shown, for the ids known.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function namesOf(SlackAccount $account, array $userIds, ?SlackApiClient $client = null): array
    {
        return $this->usersOf($account, $userIds, $client)
            ->map(fn (SlackUser $user): string => $user->displayName())
            ->all();
    }

    public function userOf(SlackAccount $account, string $userId, ?SlackApiClient $client = null): ?SlackUser
    {
        return $this->usersOf($account, [$userId], $client)->get($userId);
    }

    /**
     * Keeps a `users.list` / `users.info` member object.
     *
     * @param  array<string, mixed>  $member
     */
    public function remember(SlackAccount $account, array $member): ?SlackUser
    {
        $id = $member['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        $user = SlackUser::query()->updateOrCreate(
            ['slack_account_id' => $account->id, 'slack_user_id' => $id],
            SlackUser::attributesFrom($member),
        );

        $this->loaded[$account->id][$id] = $user;

        return $user;
    }

    public function forget(SlackAccount $account): void
    {
        unset($this->loaded[$account->id]);
    }

    private function fetch(SlackAccount $account, string $userId, SlackApiClient $client): ?SlackUser
    {
        try {
            $member = $client->userInfo($userId);
        } catch (SlackApiException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        return $this->remember($account, $member);
    }
}
