<?php

namespace App\Livewire\Profile;

use App\Actions\Devices\RevokeDevice;
use App\Actions\Notifications\SendWhatsappNotificationCode;
use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Actions\Notifications\UpdateWhatsappNotificationSettings;
use App\Actions\Notifications\VerifyWhatsappNotificationPhone;
use App\Actions\Profile\DisconnectSocialAccount;
use App\Actions\Profile\UpdatePassword;
use App\Actions\Profile\UpdatePreferences;
use App\Actions\Profile\UpdateProfile;
use App\Actions\Whatsapp\SetWhatsappPriorityColors;
use App\Actions\Workspaces\UpdateWorkspaceMember;
use App\Models\Device;
use App\Models\Module;
use App\Models\NotificationChannel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WorkspaceMembership;
use App\Services\Notifications\Channels\WhatsappChannel;
use App\Services\Notifications\NotificationPreferenceMatrix;
use App\Support\Localization;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use TallStackUi\Traits\Interactions;

/**
 * The user's own profile (US-3.1, US-3.2), copied from my-finance, in tabs
 * switched by Livewire without reloading the page (the tab is kept in the
 * URL): Profile (name, avatar and workspaces), Preferences (language and timezone),
 * Notifications, Devices and Security (password and connected accounts).
 *
 * Every change goes through its Action and is confirmed with a toast; the
 * errors of the previous attempt are cleared first, since an Action's
 * errors are not cleared by Livewire on their own. A new
 * language reloads the screen through `wire:navigate`, so the whole shell
 * is drawn in it.
 *
 * The workspaces the user is in are listed under Profile: they can choose
 * the color they see each one in and which is their default — where what
 * they create goes when they pick none (UpdateWorkspaceMember).
 *
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read Collection<int, Device> $devices
 * @property-read Collection<int, WhatsappAccount> $whatsappAccounts
 * @property-read WhatsappAccount|null $platformWhatsappAccount
 * @property-read list<array{key: string, label: string, description: string, channels: array<string, array{isEnabled: bool, isDefault: bool, isAvailable: bool}>}> $notificationRows
 * @property-read list<string> $notificationColumns
 * @property-read Collection<int, WorkspaceMembership> $memberships
 */
#[Layout('layouts::app-shell')]
class ProfilePage extends Component
{
    use Interactions, WithFileUploads;

    public const string TAB_PROFILE = 'profile';

    public const string TAB_PREFERENCES = 'preferences';

    public const string TAB_NOTIFICATIONS = 'notifications';

    public const string TAB_DEVICES = 'devices';

    public const string TAB_SECURITY = 'security';

    /**
     * @var list<string>
     */
    public const array TABS = [self::TAB_PROFILE, self::TAB_PREFERENCES, self::TAB_NOTIFICATIONS, self::TAB_DEVICES, self::TAB_SECURITY];

    #[Url]
    public string $tab = self::TAB_PROFILE;

    public string $name = '';

    /**
     * The new avatar, while it is uploaded and not saved yet.
     */
    public ?TemporaryUploadedFile $avatar = null;

    public string $locale = Localization::DEFAULT_LOCALE;

    public string $timezone = 'UTC';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * The switches of the notification matrix: type key => channel slug =>
     * enabled, saved as each one changes.
     *
     * @var array<string, array<string, bool>>
     */
    public array $preferences = [];

    /**
     * The account WhatsApp notifications go from (friendly id), US-3.3; ''
     * for the platform's account.
     */
    public string $whatsapp_notification_account = '';

    /**
     * The user's own account whose number WhatsApp notifications go to
     * (friendly id); '' for the number typed in `whatsapp_notification_phone`.
     */
    public string $whatsapp_notification_to_account = '';

    /**
     * The code sent to the number WhatsApp notifications go to, to verify it.
     */
    public string $whatsapp_notification_code = '';

    /**
     * The E.164 number WhatsApp notifications go to.
     */
    public string $whatsapp_notification_phone = '';

    /**
     * The color the user sees each of their workspaces in, saved as it
     * changes: membership id => color name, '' for the workspace's own.
     *
     * @var array<int|string, string>
     */
    public array $workspaceColors = [];

    /**
     * The color of each WhatsApp chat priority, by priority (one table for
     * every chat of every number).
     *
     * @var array<int|string, string>
     */
    public array $priorityColors = [];

    public function mount(): void
    {
        $user = $this->user();

        $this->tab = $this->validTab($this->tab);
        $this->name = $user->name;
        $this->locale = $user->locale->slug;
        $this->timezone = $user->timezone;
        $this->whatsapp_notification_account = (string) $user->whatsappNotificationAccount?->friendly_id;
        $this->fillWhatsappDestination($user);
        $this->fillPreferences();
        $this->fillWorkspaceColors();
        $this->priorityColors = $user->whatsappPriorityColors();
    }

    public function updatedTab(): void
    {
        $this->tab = $this->validTab($this->tab);
        $this->resetValidation();
    }

    /**
     * The tabs in display order, labeled in the reader's language.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        return [
            self::TAB_PROFILE => __('Profile'),
            self::TAB_PREFERENCES => __('Preferences'),
            self::TAB_NOTIFICATIONS => __('Notifications'),
            self::TAB_DEVICES => __('Devices'),
            self::TAB_SECURITY => __('Security'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function locales(): array
    {
        return Localization::NAMES;
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public function hasPassword(): bool
    {
        return $this->user()->password !== null;
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    #[Computed]
    public function socialAccounts(): Collection
    {
        return $this->user()->socialAccounts()->with('provider')->orderBy('id')->get();
    }

    /**
     * The user's own devices, the most recently used first and the never
     * used last.
     *
     * @return Collection<int, Device>
     */
    #[Computed]
    public function devices(): Collection
    {
        return $this->user()->devices()
            ->with(['platform', 'personalAccessToken'])
            ->latestUsedFirst()
            ->get();
    }

    /**
     * @return list<array{key: string, label: string, description: string, channels: array<string, array{isEnabled: bool, isDefault: bool, isAvailable: bool}>}>
     */
    #[Computed]
    public function notificationRows(): array
    {
        return app(NotificationPreferenceMatrix::class)->rowsFor($this->user());
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function notificationColumns(): array
    {
        return app(NotificationPreferenceMatrix::class)->columnsFor($this->user());
    }

    /**
     * The user's own WhatsApp accounts, to send notifications from; none
     * without the `whatsapp` module.
     *
     * @return Collection<int, WhatsappAccount>
     */
    #[Computed]
    public function whatsappAccounts(): Collection
    {
        $user = $this->user();

        return $user->hasModule(Module::WHATSAPP)
            ? $user->whatsappAccounts()->orderBy('nickname')->orderBy('id')->get()
            : new Collection;
    }

    public function usesWhatsapp(): bool
    {
        return $this->user()->hasModule(Module::WHATSAPP);
    }

    public function savePriorityColors(SetWhatsappPriorityColors $setColors): void
    {
        $this->resetValidation();
        $this->priorityColors = $setColors($this->user(), $this->priorityColors);

        $this->toast()->success(__('Priority colors saved.'))->send();
    }

    /**
     * The account the platform sends WhatsApp notifications from, when the
     * super admin picked one.
     */
    #[Computed]
    public function platformWhatsappAccount(): ?WhatsappAccount
    {
        return WhatsappChannel::platformAccount();
    }

    public function saveWhatsappNotifications(UpdateWhatsappNotificationSettings $updateSettings): void
    {
        $this->resetValidation();

        $user = $updateSettings($this->user(), [
            'account' => $this->whatsapp_notification_account,
            'to_account' => $this->whatsapp_notification_to_account,
            'phone' => $this->whatsapp_notification_to_account === '' ? $this->whatsapp_notification_phone : '',
        ]);

        $this->whatsapp_notification_account = (string) $user->whatsappNotificationAccount?->friendly_id;
        $this->fillWhatsappDestination($user);
        $this->refreshPreferences();
        $this->toast()->success(__('WhatsApp notification settings saved.'))->send();

        if ($this->needsWhatsappVerification()) {
            $this->sendWhatsappCode();
        }
    }

    /**
     * Whether the platform's account waits for the user to verify the number
     * before sending notifications to it.
     */
    public function needsWhatsappVerification(): bool
    {
        $user = $this->user();

        return filled($user->whatsapp_notification_phone)
            && $user->whatsapp_notification_account_id === null
            && ! $user->hasVerifiedWhatsappNotificationPhone();
    }

    public function sendWhatsappCode(): void
    {
        $this->resetValidation('code');

        try {
            app(SendWhatsappNotificationCode::class)($this->user());
        } catch (ValidationException $exception) {
            $this->addError('code', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->whatsapp_notification_code = '';
        $this->toast()->info(__('We sent a code to :phone on WhatsApp.', ['phone' => $this->user()->whatsapp_notification_phone]))->send();
    }

    public function verifyWhatsappCode(VerifyWhatsappNotificationPhone $verifyPhone): void
    {
        $this->resetValidation('code');

        $verifyPhone($this->user(), $this->whatsapp_notification_code);

        $this->whatsapp_notification_code = '';
        $this->refreshPreferences();
        $this->toast()->success(__('Number verified.'))->send();
    }

    /**
     * Shows the stored number as one of the user's own accounts when it is
     * the number of one of them, else as a typed number.
     */
    private function fillWhatsappDestination(User $user): void
    {
        $phone = (string) $user->whatsapp_notification_phone;
        $ownAccount = $phone === '' ? null : $this->whatsappAccounts->firstWhere('phone_number', $phone);

        $this->whatsapp_notification_to_account = (string) $ownAccount?->friendly_id;
        $this->whatsapp_notification_phone = $ownAccount === null ? $phone : '';
    }

    /**
     * The channel's name in the reader's language.
     */
    public function channelLabel(string $slug): string
    {
        return __(NotificationChannel::bySlug($slug)->name);
    }

    /**
     * A switch of the matrix changed: it becomes the user's explicit choice.
     * A refused one goes back to what is stored.
     *
     * @throws ValidationException
     */
    public function updatedPreferences(mixed $value, ?string $path = null): void
    {
        $this->resetValidation();

        [$key, $channel] = array_pad(explode('.', (string) $path, 2), 2, '');

        try {
            app(UpdateNotificationPreferences::class)($this->user(), [
                'preferences' => [$key => [$channel => filter_var($value, FILTER_VALIDATE_BOOLEAN)]],
            ]);
        } catch (ValidationException $exception) {
            $this->refreshPreferences();

            throw $exception;
        }

        $this->refreshPreferences();
        $this->toast()->success(__('Notification preferences saved.'))->send();
    }

    /**
     * The user's workspaces, by name.
     *
     * @return Collection<int, WorkspaceMembership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return $this->user()->memberships()->with('workspace')->get();
    }

    /**
     * Saves the color the user sees a workspace in: `$path` is the
     * membership id, '' goes back to the workspace's color.
     */
    public function updatedWorkspaceColors(mixed $value, ?string $path = null): void
    {
        $this->resetValidation();

        $membership = $this->ownMembership((int) $path);

        try {
            app(UpdateWorkspaceMember::class)($this->user(), $membership, [
                'color' => is_string($value) && $value !== '' ? $value : null,
            ]);
        } finally {
            $this->refreshWorkspaces();
        }

        $this->toast()->success(__('Workspace color saved.'))->send();
    }

    public function makeDefaultWorkspace(int $membershipId, UpdateWorkspaceMember $updateWorkspaceMember): void
    {
        $this->resetValidation();

        $membership = $this->ownMembership($membershipId);

        $updateWorkspaceMember($this->user(), $membership, ['is_default' => true]);

        $this->refreshWorkspaces();
        $this->toast()->success(__(':workspace is now your default workspace.', ['workspace' => $membership->workspace->name]))->send();
    }

    public function saveProfile(UpdateProfile $updateProfile): void
    {
        $this->resetValidation();

        $updateProfile($this->user(), [
            'name' => $this->name,
            'avatar' => $this->avatar,
        ]);

        $this->reset('avatar');
        $this->name = $this->user()->name;
        $this->toast()->success(__('Profile saved.'))->send();
    }

    public function savePreferences(UpdatePreferences $updatePreferences): void
    {
        $this->resetValidation();

        $user = $this->user();
        $previousLocale = $user->locale->slug;

        $updatePreferences($user, [
            'locale' => $this->locale,
            'timezone' => $this->timezone,
        ]);

        if ($user->preferredLocale() === $previousLocale) {
            $this->toast()->success(__('Preferences saved.'))->send();

            return;
        }

        // The rest of the page (and the shell around it) is in the old
        // language: the toast survives the navigation in the new one.
        App::setLocale((string) $user->preferredLocale());
        $this->toast()->success(__('Preferences saved.'))->flash()->send();
        $this->redirectRoute('profile', ['tab' => self::TAB_PREFERENCES], navigate: true);
    }

    public function savePassword(UpdatePassword $updatePassword): void
    {
        $this->resetValidation();

        $hadPassword = $this->hasPassword();

        $updatePassword($this->user(), [
            'current_password' => $this->current_password,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast()->success($hadPassword ? __('Password changed.') : __('Password set.'))->send();
    }

    public function disconnectSocialAccount(int $socialAccountId, DisconnectSocialAccount $disconnectSocialAccount): void
    {
        $this->resetValidation();

        $account = $this->user()->socialAccounts()->with('provider')->findOrFail($socialAccountId);

        $disconnectSocialAccount($this->user(), $account);

        unset($this->socialAccounts);
        $this->toast()->success(__(':provider disconnected.', ['provider' => __($account->provider->name)]))->send();
    }

    public function revokeDevice(int $deviceId, RevokeDevice $revokeDevice): void
    {
        $device = Device::query()->findOrFail($deviceId);

        $revokeDevice($this->user(), $device);

        unset($this->devices);
        // The browser revoking itself forgets its registration too.
        $this->dispatch('pwa:device-revoked', deviceId: $device->friendly_id);
        $this->toast()->success(__(':device was revoked.', ['device' => $device->name]))->send();
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page')->title(__('Profile'));
    }

    /**
     * One of the user's own memberships, 404 for anyone else's.
     */
    private function ownMembership(int $membershipId): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('user_id', $this->user()->id)
            ->with(['user', 'workspace'])
            ->findOrFail($membershipId);
    }

    private function refreshWorkspaces(): void
    {
        unset($this->memberships);
        $this->user()->forgetMemberships();
        $this->fillWorkspaceColors();
    }

    private function fillWorkspaceColors(): void
    {
        $this->workspaceColors = [];

        foreach ($this->memberships as $membership) {
            $this->workspaceColors[$membership->id] = (string) $membership->color;
        }
    }

    private function refreshPreferences(): void
    {
        unset($this->notificationRows, $this->notificationColumns);
        $this->fillPreferences();
    }

    private function fillPreferences(): void
    {
        $this->preferences = [];

        foreach ($this->notificationRows as $row) {
            foreach ($row['channels'] as $channel => $cell) {
                $this->preferences[$row['key']][$channel] = $cell['isEnabled'];
            }
        }
    }

    private function validTab(string $tab): string
    {
        return in_array($tab, self::TABS, true) ? $tab : self::TAB_PROFILE;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
