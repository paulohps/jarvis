<?php

namespace Tests\Fixtures\Notifications;

use App\Models\Module;
use App\Models\NotificationChannel;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;

/**
 * A notification of the reminders module, in the app only, for the
 * notification center tests.
 */
final class ReminderNotice extends CenterNotification
{
    public static function key(): string
    {
        return 'reminder_notice';
    }

    public static function label(): string
    {
        return 'Reminder notice';
    }

    public static function description(): string
    {
        return 'Something about a reminder.';
    }

    public static function module(): string
    {
        return Module::REMINDERS;
    }

    public static function channels(): array
    {
        return [NotificationChannel::DATABASE];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([]);
    }

    public static function links(): array
    {
        return [];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        return new ChannelDefaults('Reminder', '<p>Due now.</p>');
    }

    public function context(object $notifiable): array
    {
        return [];
    }
}
