<?php

namespace App\Observers;

use App\Models\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Support\NotificationSettingsCache;

/**
 * Drops the cached notification settings whenever a type, one of its
 * channels, a template or a platform channel is saved or deleted, so a
 * switch or a reworded template applies from the next read on instead of
 * when the cache expires (US-5.5).
 */
class NotificationSettingsObserver
{
    public function __construct(private NotificationSettingsCache $settingsCache) {}

    public function saved(NotificationType|NotificationTypeChannel|NotificationTemplate|NotificationChannel $model): void
    {
        $this->settingsCache->flush();
    }

    public function deleted(NotificationType|NotificationTypeChannel|NotificationTemplate|NotificationChannel $model): void
    {
        $this->settingsCache->flush();
    }
}
