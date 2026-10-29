<?php

namespace Tests\Fixtures\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;

/**
 * A notification about the account, in the app and on WhatsApp, for the
 * WhatsApp channel tests.
 */
final class WhatsappNotice extends CenterNotification
{
    public function __construct(public string $subject = 'the boiler') {}

    public static function key(): string
    {
        return 'whatsapp_notice';
    }

    public static function label(): string
    {
        return 'WhatsApp notice';
    }

    public static function description(): string
    {
        return 'Something worth a WhatsApp message.';
    }

    public static function module(): ?string
    {
        return null;
    }

    public static function channels(): array
    {
        return [NotificationChannel::DATABASE, NotificationChannel::WHATSAPP];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('subject', 'What it is about', 'the boiler'),
        ]);
    }

    public static function links(): array
    {
        return [
            'home' => NotificationLink::make('Open Jarvis', static fn (array $context, object $notifiable): string => 'https://jarvis.example/home'),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        return new ChannelDefaults(
            'Check {% subject %}',
            '<p>Time to check <strong>{% subject %}</strong> &amp; the pipes.</p><p>Thanks!</p>',
            [['link_key' => 'home', 'label' => 'Open']],
        );
    }

    public function context(object $notifiable): array
    {
        return ['subject' => $this->subject];
    }
}
