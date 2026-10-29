<?php

namespace Tests\Fixtures\Notifications;

use App\Models\Locale;
use App\Models\NotificationChannel;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;

/**
 * A notification about the account, in the app and by e-mail, for the
 * notification center tests.
 */
final class AccountNotice extends CenterNotification
{
    public function __construct(public string $subject = 'Hello') {}

    public static function key(): string
    {
        return 'account_notice';
    }

    public static function label(): string
    {
        return 'Account notice';
    }

    public static function description(): string
    {
        return 'Something about the account.';
    }

    public static function module(): ?string
    {
        return null;
    }

    public static function channels(): array
    {
        return [NotificationChannel::DATABASE, NotificationChannel::MAIL];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('user.name', 'Name of the recipient', 'Ana'),
            TemplateVariable::text('notice.subject', 'What it is about', 'Hello'),
        ]);
    }

    public static function links(): array
    {
        return [
            'home' => NotificationLink::make('Open', static fn (array $context, object $notifiable): string => url('/')),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        return $locale === Locale::PT_BR
            ? new ChannelDefaults('Aviso: {% notice.subject %}', '<p>Olá, {% user.name %}.</p>', [['link_key' => 'home', 'label' => 'Abrir']])
            : new ChannelDefaults('Notice: {% notice.subject %}', '<p>Hi, {% user.name %}.</p>', [['link_key' => 'home', 'label' => 'Open']]);
    }

    public function context(object $notifiable): array
    {
        return [
            'user' => ['name' => $notifiable->name ?? ''],
            'notice' => ['subject' => $this->subject],
        ];
    }
}
