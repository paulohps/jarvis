<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The cached configuration of the notification center: types, their
 * channels and templates, and the platform channels. Read on every dispatch,
 * so it lives in the cache store and, once read, in memory for the rest of
 * the request or job (bound as scoped); `NotificationSettingsObserver`
 * flushes both whenever one of those rows is saved or deleted.
 */
final class NotificationSettingsCache
{
    public const string KEY = 'notification-center.settings';

    public const int TTL_SECONDS = 3600;

    private bool $isRead = false;

    private mixed $settings = null;

    /**
     * The cached settings, built by the callback when absent.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $build
     * @return TValue
     */
    public function remember(Closure $build): mixed
    {
        if (! $this->isRead) {
            $this->settings = Cache::remember(self::KEY, self::TTL_SECONDS, $build);
            $this->isRead = true;
        }

        return $this->settings;
    }

    /**
     * Drop the cached settings, so a change applies from the next read on.
     */
    public function flush(): void
    {
        $this->isRead = false;
        $this->settings = null;

        Cache::forget(self::KEY);
    }
}
