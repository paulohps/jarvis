<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasFriendlyId;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person using the system: the super admin or someone who accepted an
 * invitation. They belong to one or more workspaces (WorkspaceMembership)
 * and see the shared data of all of them together. Module access is per
 * membership (a row in `module_workspace_user`); the super admin has every
 * module in the workspaces they are a member of, without rows.
 *
 * @property array<string, string>|null $whatsapp_priority_colors
 * @property int $id
 * @property string $friendly_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property string|null $avatar_path
 * @property int $locale_id
 * @property string $timezone
 * @property bool $is_super_admin
 * @property int|null $whatsapp_notification_account_id
 * @property string|null $whatsapp_notification_phone
 * @property Carbon|null $whatsapp_notification_phone_verified_at
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $avatar_url
 * @property-read Locale $locale
 * @property-read WhatsappAccount|null $whatsappNotificationAccount
 * @property-read Collection<int, WorkspaceMembership> $memberships
 */
#[Fillable(['name', 'email', 'password', 'avatar_path', 'locale_id', 'timezone', 'whatsapp_notification_account_id', 'whatsapp_notification_phone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasFriendlyId, Notifiable, SoftDeletes;

    public static function idPrefix(): string
    {
        return 'usr';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'deactivated_at' => 'datetime',
            'whatsapp_priority_colors' => 'array',
            'last_seen_at' => 'datetime',
            'whatsapp_notification_phone_verified_at' => 'datetime',
        ];
    }

    /**
     * The public URL of the avatar, or null when there is none.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->avatar_path === null
            ? null
            : Storage::disk(config('filesystems.media'))->url($this->avatar_path));
    }

    /**
     * Whether the user may use the module somewhere: in any of their
     * workspaces with it switched on — always for the super admin. The
     * routes' `module:` check; the data shown is still only that of the
     * workspaces in workspaceIdsWithModule().
     */
    public function hasModule(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->workspaceIdsWithModule($slug) !== [];
    }

    /**
     * Whether the user may use the chat provider (a ChatProvider slug) on
     * the chat screen: in any workspace where they have the `chats` module
     * and the super admin switched the provider on both for the workspace
     * and for them — always for the super admin, while the provider is
     * active.
     */
    public function hasChatProvider(string $providerSlug): bool
    {
        $provider = ChatProvider::query()->slug($providerSlug)->first();

        if ($provider === null || ! $provider->is_active) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $this->loadMissing(['memberships.chatProviders', 'memberships.workspace.chatProviders']);

        return $this->membershipsWithModule(Module::CHATS)
            ->contains(fn (WorkspaceMembership $membership): bool => $membership->hasChatProvider($providerSlug)
                && $membership->workspace->hasChatProvider($providerSlug));
    }

    /**
     * The ids of the workspaces whose `$moduleSlug` data the user sees: the
     * ones where the module is switched on for them — every workspace they
     * are a member of, for the super admin. Read from the loaded
     * memberships; forgetMemberships() after changing them.
     *
     * @return list<int>
     */
    public function workspaceIdsWithModule(string $moduleSlug): array
    {
        return $this->membershipsWithModule($moduleSlug)
            ->pluck('workspace_id')
            ->values()
            ->all();
    }

    /**
     * Whether the user sees the `$moduleSlug` data of the workspace.
     */
    public function hasModuleIn(Workspace|int $workspace, string $moduleSlug): bool
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return in_array($workspaceId, $this->workspaceIdsWithModule($moduleSlug), true);
    }

    /**
     * The memberships where `$moduleSlug` is on for the user, with their
     * workspace, by workspace name.
     *
     * @return Collection<int, WorkspaceMembership>
     */
    public function membershipsWithModule(string $moduleSlug): Collection
    {
        $this->loadMissing(['memberships.modules', 'memberships.workspace']);

        return $this->memberships
            ->filter(fn (WorkspaceMembership $membership): bool => $this->isSuperAdmin() || $membership->hasModule($moduleSlug))
            ->values();
    }

    /**
     * Where what the user creates in `$moduleSlug` goes when they pick no
     * workspace: their default one when the module is on there, else the
     * first with it (by name). Null when they have it nowhere.
     */
    public function defaultWorkspaceFor(string $moduleSlug): ?Workspace
    {
        $memberships = $this->membershipsWithModule($moduleSlug);

        return ($memberships->firstWhere('is_default', true) ?? $memberships->first())?->workspace;
    }

    /**
     * The user's membership of the workspace, if they are in it.
     */
    public function membershipOf(Workspace|int $workspace): ?WorkspaceMembership
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $this->loadMissing(['memberships.modules', 'memberships.workspace']);

        return $this->memberships->firstWhere('workspace_id', $workspaceId);
    }

    /**
     * The color name the user sees the workspace in: their membership's
     * own, else the workspace's.
     */
    public function colorOf(Workspace $workspace): string
    {
        return $this->membershipOf($workspace)?->color ?? $workspace->color;
    }

    /**
     * Drops the loaded memberships, so the next check reads them again.
     */
    public function forgetMemberships(): void
    {
        $this->unsetRelation('memberships');
        $this->unsetRelation('workspaces');
    }

    /**
     * The interface language the user picked (US-3.2), read by SetLocale and
     * by notifications sent to the user.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale->slug;
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Deactivated users keep their data but can no longer sign in (US-2.2).
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }

    /**
     * The user's places in workspaces, by workspace name.
     *
     * @return HasMany<WorkspaceMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class)
            ->select('workspace_user.*')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->orderBy('workspaces.name')
            ->orderBy('workspaces.id');
    }

    /**
     * @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'membership'>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'color', 'is_default'])
            ->withTimestamps()
            ->orderBy('workspaces.name');
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * The color of each WhatsApp chat priority, 1 to 5 (a name of
     * WhatsappTarget::PRIORITY_COLORS): the user's choice, else the default.
     *
     * @return array<int, string>
     */
    public function whatsappPriorityColors(): array
    {
        $chosen = is_array($this->whatsapp_priority_colors) ? $this->whatsapp_priority_colors : [];
        $colors = [];

        foreach (WhatsappTarget::DEFAULT_PRIORITY_COLORS as $priority => $default) {
            $color = $chosen[$priority] ?? $chosen[(string) $priority] ?? null;
            $colors[$priority] = is_string($color) && isset(WhatsappTarget::PRIORITY_COLORS[$color]) ? $color : $default;
        }

        return $colors;
    }

    /**
     * The user's own tags for WhatsApp chats.
     *
     * @return HasMany<WhatsappTag, $this>
     */
    public function whatsappTags(): HasMany
    {
        return $this->hasMany(WhatsappTag::class)->orderBy('name');
    }

    /**
     * @return HasMany<WhatsappAccount, $this>
     */
    public function whatsappAccounts(): HasMany
    {
        return $this->hasMany(WhatsappAccount::class);
    }

    /**
     * The Slack workspaces the user connected to the chat screen.
     *
     * @return HasMany<SlackAccount, $this>
     */
    public function slackAccounts(): HasMany
    {
        return $this->hasMany(SlackAccount::class)->orderBy('team_name')->orderBy('id');
    }

    /**
     * The conversations of the chat screen, of every provider.
     *
     * @return HasMany<ChatConversation, $this>
     */
    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    /**
     * The user's own tags for the conversations of the chat screen.
     *
     * @return HasMany<ChatTag, $this>
     */
    public function chatTags(): HasMany
    {
        return $this->hasMany(ChatTag::class)->orderBy('name');
    }

    /**
     * The people the user linked conversations of several providers to.
     *
     * @return HasMany<ChatContact, $this>
     */
    public function chatContacts(): HasMany
    {
        return $this->hasMany(ChatContact::class)->orderBy('name');
    }

    /**
     * The account the WhatsApp notification channel sends from (US-3.3).
     *
     * @return BelongsTo<WhatsappAccount, $this>
     */
    public function whatsappNotificationAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_notification_account_id');
    }

    /**
     * Whether the user proved the number WhatsApp notifications go to is
     * theirs, by a code sent to it or by connecting it as their account.
     */
    public function hasVerifiedWhatsappNotificationPhone(): bool
    {
        return filled($this->whatsapp_notification_phone) && $this->whatsapp_notification_phone_verified_at !== null;
    }

    /**
     * @return HasMany<NotificationPreference, $this>
     */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /**
     * The in-app notifications, newest first, as the center's model.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitationsSent(): HasMany
    {
        return $this->hasMany(Invitation::class, 'invited_by_id');
    }
}
