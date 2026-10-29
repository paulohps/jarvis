<?php

namespace App\Support\Navigation;

use App\Models\Module;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The navigation of the signed-in area (US-12.2, US-2.1): Home, then one
 * entry per module the user has (`hasModule`: the super admin has them all),
 * in the modules' `sort_order`, then "Admin" for the super admin only.
 * WhatsApp and Chats are one group, "Chats" (messagingGroup()): inbox,
 * scheduled, history and channels, where the first of them is. The sidebar leaves "Admin" out of that list and shows
 * the admin screens in their own section near its end, grouped
 * (`adminSectionsFor()`); the tab bar keeps the single "Admin" entry.
 *
 * Each module opens its `<slug>.index` route and the admin area
 * `admin.index`; those routes arrive with their phases.
 */
final class AppNavigation
{
    public const string HOME_ROUTE = 'dashboard';

    public const string ADMIN_ROUTE = 'admin.index';

    /**
     * The Heroicon of each module.
     *
     * @var array<string, string>
     */
    private const array MODULE_ICONS = [
        Module::INVOICES => 'document-text',
        Module::WHATSAPP => 'chat-bubble-left-right',
        Module::SHOPPING => 'shopping-cart',
        Module::REMINDERS => 'calendar-days',
        Module::CHATS => 'chat-bubble-oval-left-ellipsis',
    ];

    /**
     * Every entry of the user's navigation.
     *
     * @return list<NavigationItem>
     */
    public static function for(User $user): array
    {
        $items = [
            new NavigationItem('home', __('Home'), self::HOME_ROUTE, 'home'),
            ...self::modulesFor($user),
        ];

        if ($user->isSuperAdmin()) {
            $items[] = new NavigationItem('admin', __('Admin'), self::ADMIN_ROUTE, 'shield-check');
        }

        return $items;
    }

    /**
     * The admin screens, for the super admin only (empty for anyone else),
     * gathered as adminGroups() says.
     *
     * @return list<NavigationItem>
     */
    public static function adminSectionsFor(User $user): array
    {
        if (! $user->isSuperAdmin()) {
            return [];
        }

        $sections = self::adminSections();
        $item = fn (string $route): NavigationItem => new NavigationItem(
            'admin-'.str_replace(['admin.', '.index'], '', $route),
            $sections[$route]['title'],
            $route,
            $sections[$route]['icon'],
        );

        return array_map(
            fn (array $group): NavigationItem => count($group['routes']) === 1
                ? $item($group['routes'][0])
                : new NavigationItem($group['key'], $group['title'], $group['routes'][0], $group['icon'], array_map($item, $group['routes'])),
            self::adminGroups(),
        );
    }

    /**
     * The admin screens gathered by what they are about, in order: a group
     * of one is shown as that screen.
     *
     * @return list<array{key: string, title: string, icon: string, routes: list<string>}>
     */
    public static function adminGroups(): array
    {
        return [
            ['key' => 'admin-people', 'title' => __('People'), 'icon' => 'users', 'routes' => ['admin.users.index', 'admin.invitations.index', 'admin.workspaces.index']],
            ['key' => 'admin-ai', 'title' => __('AI'), 'icon' => 'sparkles', 'routes' => ['admin.ai-providers.index', 'admin.ai-usage.index']],
            ['key' => 'admin-notifications', 'title' => __('Notifications'), 'icon' => 'bell', 'routes' => ['admin.notifications.index']],
            ['key' => 'admin-mail-services', 'title' => __('Mail services'), 'icon' => 'at-symbol', 'routes' => ['mail-services.index']],
        ];
    }

    /**
     * The admin screens: route name => title, subtitle and icon.
     *
     * @return array<string, array{title: string, subtitle: string, icon: string}>
     */
    public static function adminSections(): array
    {
        return [
            'admin.users.index' => ['title' => __('Users'), 'subtitle' => __('Last access, deactivate and reactivate'), 'icon' => 'users'],
            'admin.workspaces.index' => ['title' => __('Workspaces'), 'subtitle' => __('Families, their members and modules'), 'icon' => 'user-group'],
            'admin.invitations.index' => ['title' => __('Invitations'), 'subtitle' => __('Invite someone of the family'), 'icon' => 'envelope'],
            'admin.notifications.index' => ['title' => __('Notifications'), 'subtitle' => __('Types, channels and texts'), 'icon' => 'bell'],
            'admin.ai-providers.index' => ['title' => __('AI provider'), 'subtitle' => __('The remote AI of the voice commands'), 'icon' => 'sparkles'],
            'admin.ai-usage.index' => ['title' => __('AI usage'), 'subtitle' => __('Calls, tokens, cache and spend per model'), 'icon' => 'chart-bar'],
            'mail-services.index' => ['title' => __('Mail services'), 'subtitle' => __('SMTP, Google accounts and Resend, global ones included'), 'icon' => 'at-symbol'],
        ];
    }

    /**
     * One entry per active module switched on for the user.
     *
     * @return list<NavigationItem>
     */
    public static function modulesFor(User $user): array
    {
        $modules = self::enabledModules($user)->filter(fn (Module $module): bool => $user->hasModule($module->slug));
        $slugs = array_values($modules->map(fn (Module $module): string => $module->slug)->all());
        $messaging = array_values(array_filter($slugs, fn (string $slug): bool => in_array($slug, [Module::WHATSAPP, Module::CHATS], true)));
        $items = [];

        foreach ($modules as $module) {
            if (! in_array($module->slug, $messaging, true)) {
                $items[] = self::moduleItem($module);
            } elseif ($module->slug === $messaging[0]) {
                $items[] = self::messagingGroup($messaging);
            }
        }

        return $items;
    }

    /**
     * WhatsApp and Chats as one entry, organized by what the user does, not
     * by channel: the inbox (every channel on the chat screen; the WhatsApp
     * screen without the chats module), the scheduled messages, the sending
     * history and the channels (where WhatsApp numbers and Slack workspaces
     * are connected, the WhatsApp screen among them) — only those of the
     * modules the user has.
     *
     * @param  list<string>  $modules  `whatsapp` and/or `chats`
     */
    public static function messagingGroup(array $modules): NavigationItem
    {
        $hasWhatsapp = in_array(Module::WHATSAPP, $modules, true);
        $hasChats = in_array(Module::CHATS, $modules, true);

        $children = array_values(array_filter([
            new NavigationItem('chats-inbox', __('Inbox'), $hasChats ? 'chats.chat' : 'whatsapp.chat', 'inbox', activeRoutes: $hasChats ? ['whatsapp.chat'] : []),
            match (true) {
                $hasChats => new NavigationItem('chats-scheduled', __('Scheduled'), 'chats.scheduled', 'clock', activeRoutes: $hasWhatsapp ? ['whatsapp.messages.*'] : []),
                $hasWhatsapp => new NavigationItem('chats-scheduled', __('Scheduled'), 'whatsapp.messages.index', 'clock'),
                default => null,
            },
            match (true) {
                $hasChats => new NavigationItem('chats-history', __('Sending history'), 'chats.history', 'queue-list', activeRoutes: $hasWhatsapp ? ['whatsapp.history.*'] : []),
                $hasWhatsapp => new NavigationItem('chats-history', __('Sending history'), 'whatsapp.history.index', 'queue-list'),
                default => null,
            },
            new NavigationItem('chats-channels', __('Channels'), $hasChats ? 'chats.index' : 'whatsapp.accounts.index', 'squares-2x2', activeRoutes: $hasWhatsapp ? ['whatsapp.accounts.*'] : []),
        ]));

        return new NavigationItem(
            'messaging',
            __('Chats'),
            $children[0]->route,
            self::MODULE_ICONS[Module::CHATS],
            $children,
            array_values(array_filter([$hasWhatsapp ? 'whatsapp.*' : null, $hasChats ? 'chats.*' : null])),
        );
    }

    public static function moduleItem(Module $module): NavigationItem
    {
        return new NavigationItem(
            $module->slug,
            self::moduleLabel($module),
            "{$module->slug}.index",
            self::MODULE_ICONS[$module->slug] ?? 'squares-2x2',
        );
    }

    /**
     * The module's name in the current language.
     */
    public static function moduleLabel(Module $module): string
    {
        return match ($module->slug) {
            Module::INVOICES => __('Invoices'),
            Module::WHATSAPP => __('WhatsApp'),
            Module::SHOPPING => __('Shopping list'),
            Module::REMINDERS => __('Agenda'),
            Module::CHATS => __('Chats'),
            default => $module->name,
        };
    }

    /**
     * The active modules the user may open — switched on in any of their
     * workspaces — in menu order, with the user's memberships loaded so
     * `hasModule` asks the database only once.
     *
     * @return Collection<int, Module>
     */
    public static function enabledModules(User $user): Collection
    {
        if ($user->isSuperAdmin()) {
            return Module::query()->active()->orderBy('sort_order')->orderBy('id')->get();
        }

        $user->loadMissing(['memberships.modules', 'memberships.workspace']);

        return (new Collection($user->memberships->flatMap(fn ($membership) => $membership->modules)->all()))
            ->unique('id')
            ->where('is_active', true)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();
    }
}
