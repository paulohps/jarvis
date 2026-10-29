<?php

namespace App\Livewire\Chats;

use App\Actions\Slack\DisconnectSlackAccount;
use App\Actions\Slack\ResyncSlackAccount;
use App\Events\NotificationCreated;
use App\Http\Controllers\Chats\SlackConnectCallbackController;
use App\Models\ChatProvider;
use App\Models\SlackAccount;
use App\Models\User;
use App\Services\Chats\ChatAccountData;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The home of the chats module: the link to the chat screen (a tab of its
 * own) and, per channel the super admin switched on for the user, the
 * accounts they connected — a Slack workspace with its state, "Sync
 * conversations" (ResyncSlackAccount) and "Disconnect"
 * (DisconnectSlackAccount) — and how to connect one more. What the Slack
 * callback flashes shows here; an account finishing its sync
 * (`ChatAccountSynced`) refreshes the list.
 *
 * @property-read Collection<int, ChatProvider> $providers
 * @property-read BaseCollection<string, BaseCollection<int, ChatAccountData>> $accounts
 */
#[Layout('layouts::app-shell')]
class ChatChannelList extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->status = session(SlackConnectCallbackController::STATUS_KEY);
        $this->error = session(SlackConnectCallbackController::ERROR_KEY);
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return ['echo-private:'.NotificationCreated::channelFor($this->user()->id).',.chat.account.synced' => '$refresh'];
    }

    /**
     * The channels the user may use, in menu order.
     *
     * @return Collection<int, ChatProvider>
     */
    #[Computed]
    public function providers(): Collection
    {
        return app(ChatProviderRegistry::class)->providersFor($this->user());
    }

    /**
     * The user's accounts, by provider slug.
     *
     * @return BaseCollection<string, BaseCollection<int, ChatAccountData>>
     */
    #[Computed]
    public function accounts(): BaseCollection
    {
        $registry = app(ChatProviderRegistry::class);

        return $this->providers->mapWithKeys(fn (ChatProvider $provider): array => [
            $provider->slug => $registry->driver($provider->slug)->accountsOf($this->user()),
        ])->toBase();
    }

    public function resyncSlack(string $accountId, ResyncSlackAccount $resync): void
    {
        $resync($this->user(), $this->slackAccount($accountId));

        $this->status = __('The conversations are being synced. They show up on the chat screen as they come.');
        $this->error = null;
    }

    public function disconnectSlack(string $accountId, DisconnectSlackAccount $disconnect): void
    {
        $account = $this->slackAccount($accountId);
        $name = $account->team_name;

        $disconnect($this->user(), $account);

        unset($this->accounts);
        $this->status = __(':workspace was disconnected.', ['workspace' => $name]);
        $this->error = null;
    }

    public function render(): View
    {
        return view('livewire.chats.chat-channel-list')->title(__('Chats'));
    }

    private function slackAccount(string $friendlyId): SlackAccount
    {
        return SlackAccount::query()->where('user_id', $this->user()->id)->byFriendlyId($friendlyId)->firstOrFail();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
