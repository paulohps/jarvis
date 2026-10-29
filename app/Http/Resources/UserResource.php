<?php

namespace App\Http\Resources;

use App\Events\NotificationCreated;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Navigation\AppNavigation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user as the apps see it (US-1.5, US-11.1): who they are,
 * their language and timezone, and the modules switched on for them — every
 * active one for the super admin — so the app builds its tabs from
 * `is_available_on_mobile`, and the private Reverb channel the app listens
 * to for the user's notifications (US-5.1), whose name carries the numeric
 * id the API otherwise never shows.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'friendly_id' => $user->friendly_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'locale' => $user->locale->slug,
            'timezone' => $user->timezone,
            'is_super_admin' => $user->isSuperAdmin(),
            'modules' => AppNavigation::enabledModules($user)
                ->map(fn (Module $module): array => [
                    'slug' => $module->slug,
                    'name' => AppNavigation::moduleLabel($module),
                    'is_available_on_mobile' => $module->is_available_on_mobile,
                ])
                ->values()
                ->all(),
            'workspaces' => self::workspacesOf($user),
            'broadcast_channel' => 'private-'.NotificationCreated::channelFor($user->id),
        ];
    }

    /**
     * @return list<array{id: string, name: string, color: string, color_hex: string, workspace_color: string, own_color: string|null, is_default: bool, modules: list<string>}>
     */
    public static function workspacesOf(User $user): array
    {
        $user->loadMissing(['memberships.modules', 'memberships.workspace']);
        $activeModules = AppNavigation::enabledModules($user);

        return $user->memberships
            ->map(fn (WorkspaceMembership $membership): array => [
                'id' => $membership->workspace->friendly_id,
                'name' => $membership->workspace->name,
                'color' => $membership->effectiveColor(),
                'color_hex' => Workspace::hexOf($membership->effectiveColor()),
                'workspace_color' => $membership->workspace->color,
                'own_color' => $membership->color,
                'is_default' => $membership->is_default,
                'modules' => $activeModules
                    ->filter(fn (Module $module): bool => $user->isSuperAdmin() || $membership->hasModule($module->slug))
                    ->pluck('slug')
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
