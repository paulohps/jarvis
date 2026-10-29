<?php

namespace App\Livewire\Admin\Notifications;

use App\Actions\Notifications\UpdateNotificationType;
use App\Actions\Notifications\UpdateNotificationTypeChannel;
use App\Models\MailService;
use App\Models\NotificationChannel;
use App\Models\NotificationType;
use App\Models\NotificationTypeChannel;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Notifications\Channels\ChannelDrivers;
use App\Services\Notifications\NotificationRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The notification center in the admin area (US-5.5, ported from
 * my-finance): the platform channels, then every registered type with its
 * switches — the type itself, whether the profile offers it and, for each of
 * its channels, whether it delivers and whether users who never chose get
 * it, plus the mail service its e-mail goes through — and the account the
 * platform sends WhatsApp notifications from. Each switch is saved as soon
 * as it changes.
 *
 * The list comes from the registered classes, so a type deployed without its
 * rows shows as "not provisioned" (it is never sent) until
 * `notifications:provision` runs.
 *
 * @property-read Collection<int, NotificationChannel> $platformChannels
 * @property-read Collection<int, MailService> $mailServices
 * @property-read Collection<int, WhatsappAccount> $whatsappAccounts
 * @property-read Collection<string, NotificationType> $storedTypes
 * @property-read list<array{key: string, label: string, description: string, isProvisioned: bool, channels: list<string>}> $types
 */
#[Layout('layouts::app-shell')]
class NotificationTypeList extends Component
{
    /**
     * @var array<string, bool> channel slug => the platform delivers through it
     */
    public array $platformActive = [];

    /**
     * @var array<string, bool> type key => the type is active
     */
    public array $active = [];

    /**
     * @var array<string, bool> type key => the profile offers the type
     */
    public array $visibleInProfile = [];

    /**
     * @var array<string, array<string, bool>> type key => channel slug => the channel delivers the type
     */
    public array $channelActive = [];

    /**
     * @var array<string, array<string, bool>> type key => channel slug => users who never chose get it
     */
    public array $enabledByDefault = [];

    /**
     * @var array<string, string> type key => friendly id of the global mail service its e-mail goes through ('' for the default mailer)
     */
    public array $mailService = [];

    /**
     * Friendly id of the account the platform sends WhatsApp notifications from ('' for none).
     */
    public string $whatsappAccount = '';

    public function mount(): void
    {
        abort_unless($this->actor()->isSuperAdmin(), 403);

        $this->readSwitches();
    }

    /**
     * @return Collection<int, NotificationChannel>
     */
    #[Computed]
    public function platformChannels(): Collection
    {
        return NotificationChannel::query()->with('whatsappAccount')->orderBy('id')->get();
    }

    /**
     * The services the e-mail of a type may go through.
     *
     * @return Collection<int, MailService>
     */
    #[Computed]
    public function mailServices(): Collection
    {
        return MailService::query()->forNotifications()->with('type')->orderBy('name')->get();
    }

    /**
     * The accounts the platform's WhatsApp notifications may go from.
     *
     * @return Collection<int, WhatsappAccount>
     */
    #[Computed]
    public function whatsappAccounts(): Collection
    {
        return WhatsappAccount::query()->forNotifications()->with('user')->orderBy('nickname')->orderBy('id')->get();
    }

    /**
     * The rows of the registered types, by key.
     *
     * @return Collection<string, NotificationType>
     */
    #[Computed]
    public function storedTypes(): Collection
    {
        return NotificationType::query()
            ->whereIn('slug', array_keys(app(NotificationRegistry::class)->map()))
            ->with(['typeChannels.channel', 'typeChannels.mailService'])
            ->get()
            ->keyBy('slug');
    }

    /**
     * Every registered type, by label.
     *
     * @return list<array{key: string, label: string, description: string, isProvisioned: bool, channels: list<string>}>
     */
    #[Computed]
    public function types(): array
    {
        $types = [];

        foreach (app(NotificationRegistry::class)->classes() as $class) {
            $stored = $this->storedTypes->get($class::key());

            $types[] = [
                'key' => $class::key(),
                'label' => $class::label(),
                'description' => $class::description(),
                'isProvisioned' => $stored !== null,
                'channels' => $stored === null ? [] : array_values(array_filter(
                    $class::channels(),
                    fn (string $slug): bool => $this->typeChannelOf($stored, $slug) !== null,
                )),
            ];
        }

        usort($types, static fn (array $first, array $second): int => strnatcasecmp($first['label'], $second['label']));

        return $types;
    }

    /**
     * The channel's name in the reader's language.
     */
    public function channelLabel(string $slug): string
    {
        return __($this->platformChannels->firstWhere('slug', $slug)->name ?? $slug);
    }

    public function isDelivered(string $slug): bool
    {
        return ChannelDrivers::isImplemented($slug);
    }

    public function updatedPlatformActive(mixed $value, ?string $slug = null): void
    {
        $channel = $this->platformChannels->firstWhere('slug', (string) $slug);

        if ($channel instanceof NotificationChannel) {
            app(UpdateNotificationTypeChannel::class)($this->actor(), $channel, [
                'is_active' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $this->refreshSwitches();
    }

    public function updatedWhatsappAccount(mixed $value): void
    {
        $channel = $this->platformChannels->firstWhere('slug', NotificationChannel::WHATSAPP);

        if ($channel instanceof NotificationChannel) {
            app(UpdateNotificationTypeChannel::class)($this->actor(), $channel, [
                'whatsapp_account' => is_string($value) && $value !== '' ? $value : null,
            ]);
        }

        $this->refreshSwitches();
    }

    public function updatedActive(mixed $value, ?string $key = null): void
    {
        $this->saveType((string) $key, 'is_active', $value);
    }

    public function updatedVisibleInProfile(mixed $value, ?string $key = null): void
    {
        $this->saveType((string) $key, 'is_visible_in_profile', $value);
    }

    public function updatedChannelActive(mixed $value, ?string $path = null): void
    {
        $this->saveChannel((string) $path, 'is_active', $value);
    }

    public function updatedEnabledByDefault(mixed $value, ?string $path = null): void
    {
        $this->saveChannel((string) $path, 'is_enabled_by_default', $value);
    }

    public function updatedMailService(mixed $value, ?string $key = null): void
    {
        $type = $this->storedTypes->get((string) $key);
        $typeChannel = $type instanceof NotificationType ? $this->typeChannelOf($type, NotificationChannel::MAIL) : null;

        if ($typeChannel !== null) {
            app(UpdateNotificationTypeChannel::class)($this->actor(), $typeChannel, [
                'mail_service' => is_string($value) && $value !== '' ? $value : null,
            ]);
        }

        $this->refreshSwitches();
    }

    public function render(): View
    {
        return view('livewire.admin.notifications.notification-type-list')->title(__('Notifications'));
    }

    private function saveType(string $key, string $attribute, mixed $value): void
    {
        $type = $this->storedTypes->get($key);

        if ($type instanceof NotificationType) {
            app(UpdateNotificationType::class)($this->actor(), $type, [
                $attribute => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $this->refreshSwitches();
    }

    private function saveChannel(string $path, string $attribute, mixed $value): void
    {
        [$key, $slug] = array_pad(explode('.', $path, 2), 2, '');
        $type = $this->storedTypes->get($key);
        $typeChannel = $type instanceof NotificationType ? $this->typeChannelOf($type, $slug) : null;

        if ($typeChannel !== null) {
            app(UpdateNotificationTypeChannel::class)($this->actor(), $typeChannel, [
                $attribute => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $this->refreshSwitches();
    }

    /**
     * Reads the rows again and puts every switch back to what is stored, so
     * a switch of an unknown type or channel does not linger on screen.
     */
    private function refreshSwitches(): void
    {
        unset($this->platformChannels, $this->storedTypes, $this->types, $this->mailServices, $this->whatsappAccounts);

        $this->readSwitches();
    }

    private function readSwitches(): void
    {
        $this->platformActive = $this->platformChannels
            ->mapWithKeys(fn (NotificationChannel $channel): array => [$channel->slug => $channel->is_active])
            ->all();
        $this->whatsappAccount = (string) $this->platformChannels->firstWhere('slug', NotificationChannel::WHATSAPP)?->whatsappAccount?->friendly_id;
        $this->active = [];
        $this->visibleInProfile = [];
        $this->channelActive = [];
        $this->enabledByDefault = [];
        $this->mailService = [];

        foreach ($this->storedTypes as $key => $type) {
            $this->active[$key] = $type->is_active;
            $this->visibleInProfile[$key] = $type->is_visible_in_profile;

            foreach ($type->typeChannels as $typeChannel) {
                $this->channelActive[$key][$typeChannel->channel->slug] = $typeChannel->is_active;
                $this->enabledByDefault[$key][$typeChannel->channel->slug] = $typeChannel->is_enabled_by_default;

                if ($typeChannel->channel->slug === NotificationChannel::MAIL) {
                    $this->mailService[$key] = (string) $typeChannel->mailService?->friendly_id;
                }
            }
        }
    }

    private function typeChannelOf(NotificationType $type, string $slug): ?NotificationTypeChannel
    {
        return $type->typeChannels->first(fn (NotificationTypeChannel $typeChannel): bool => $typeChannel->channel->slug === $slug);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
