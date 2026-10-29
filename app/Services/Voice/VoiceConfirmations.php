<?php

namespace App\Services\Voice;

use App\Models\User;
use App\Models\WhatsappTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The pending confirmations of voice sends (US-10.3, Workflow 13): a voice
 * WhatsApp send is never sent before the user confirms it. The send waits
 * in the cache under a random token for TTL_MINUTES, bound to the user;
 * `claim()` takes it out, so a token works once.
 */
final class VoiceConfirmations
{
    public const int TTL_MINUTES = 5;

    /**
     * Keeps a send waiting for confirmation and returns its token and when
     * it expires.
     *
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function issue(User $actor, WhatsappTarget $target, string $body): array
    {
        $token = Str::random(48);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TTL_MINUTES);

        Cache::put($this->key($token), [
            'user_id' => $actor->id,
            'target_id' => $target->id,
            'body' => $body,
        ], $expiresAt);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * The send waiting under the token, taken out of the cache: null when
     * the token is unknown, expired, already used or someone else's.
     *
     * @return array{target_id: int, body: string}|null
     */
    public function claim(User $actor, string $token): ?array
    {
        $key = $this->key($token);

        /** @var array{user_id: int, target_id: int, body: string}|null $pending */
        $pending = Cache::get($key);

        if ($pending === null || $pending['user_id'] !== $actor->id) {
            return null;
        }

        // Atomic: of two confirmations at the same time, only one claims it.
        if (! Cache::add($key.':claimed', true, now()->addMinutes(self::TTL_MINUTES))) {
            return null;
        }

        Cache::forget($key);

        return ['target_id' => $pending['target_id'], 'body' => $pending['body']];
    }

    private function key(string $token): string
    {
        return 'voice-confirmation:'.hash('sha256', $token);
    }
}
