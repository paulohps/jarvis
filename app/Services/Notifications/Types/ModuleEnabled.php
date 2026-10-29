<?php

namespace App\Services\Notifications\Types;

use App\Models\Locale;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\Notifications\CenterNotification;
use App\Services\Notifications\ChannelDefaults;
use App\Services\Notifications\NotificationLink;
use App\Services\Notifications\NotificationSchema;
use App\Services\Templates\TemplateSchema;
use App\Services\Templates\TemplateVariable;
use App\Support\Navigation\AppNavigation;
use Illuminate\Support\Facades\Route;

/**
 * The super admin switched a module on for a user (US-2.1, US-5.4): in the
 * app, by e-mail and on WhatsApp, with the module's name in the user's language and the
 * link that opens it. A notification about the account, so it is not held
 * back by the module it announces.
 */
final class ModuleEnabled extends CenterNotification
{
    public function __construct(public Module $enabledModule) {}

    public static function key(): string
    {
        return NotificationType::MODULE_ENABLED;
    }

    public static function label(): string
    {
        return __('Module enabled');
    }

    public static function description(): string
    {
        return __('Tells a user that a module was switched on for them.');
    }

    public static function module(): ?string
    {
        return null;
    }

    public static function channels(): array
    {
        return [NotificationChannel::DATABASE, NotificationChannel::MAIL, NotificationChannel::WHATSAPP];
    }

    public static function schema(): TemplateSchema
    {
        return NotificationSchema::make([
            TemplateVariable::text('user.name', 'Name of whoever receives the notification', 'Ana'),
            TemplateVariable::text('module.name', 'Name of the module', 'Reminders'),
            TemplateVariable::text('module.url', 'The link that opens the module', 'https://jarvis.example/reminders'),
        ]);
    }

    public static function links(): array
    {
        return [
            'module' => NotificationLink::make(
                'Open the module',
                static fn (array $context, object $notifiable): string => (string) ($context['module']['url'] ?? self::moduleUrl((string) ($context['module']['slug'] ?? ''))),
            ),
        ];
    }

    public static function defaults(string $channel, string $locale): ChannelDefaults
    {
        if ($channel === NotificationChannel::WHATSAPP) {
            return match ($locale) {
                Locale::PT_BR => new ChannelDefaults(
                    title: 'O módulo {% module.name %} foi liberado',
                    body: 'Olá, {% user.name %}. Agora você pode usar o módulo {% module.name %} no Jarvis: {% module.url %}',
                ),
                default => new ChannelDefaults(
                    title: '{% module.name %} is now available',
                    body: 'Hi, {% user.name %}. You can now use {% module.name %} in Jarvis: {% module.url %}',
                ),
            };
        }

        return match ($locale) {
            Locale::PT_BR => new ChannelDefaults(
                title: 'O módulo {% module.name %} foi liberado',
                body: '<p>Olá, {% user.name %}. Agora você pode usar o módulo {% module.name %} no Jarvis.</p>',
                actions: [['link_key' => 'module', 'label' => 'Abrir {% module.name %}']],
            ),
            default => new ChannelDefaults(
                title: '{% module.name %} is now available',
                body: '<p>Hi, {% user.name %}. You can now use {% module.name %} in Jarvis.</p>',
                actions: [['link_key' => 'module', 'label' => 'Open {% module.name %}']],
            ),
        };
    }

    public function context(object $notifiable): array
    {
        return [
            'user' => ['name' => $notifiable instanceof User ? $notifiable->name : ''],
            'module' => [
                'name' => AppNavigation::moduleLabel($this->enabledModule),
                'slug' => $this->enabledModule->slug,
                'url' => self::moduleUrl($this->enabledModule->slug),
            ],
        ];
    }

    /**
     * The module's screen, or the start page until the module has one.
     */
    private static function moduleUrl(string $slug): string
    {
        $route = "{$slug}.index";

        return Route::has($route) ? route($route) : route(AppNavigation::HOME_ROUTE);
    }
}
