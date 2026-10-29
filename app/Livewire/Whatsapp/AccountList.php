<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\RefreshWhatsappConnectionStatus;
use App\Actions\Whatsapp\RemoveWhatsappAccount;
use App\Actions\Whatsapp\RenameWhatsappAccount;
use App\Actions\Whatsapp\RestartWhatsappConnection;
use App\Actions\Whatsapp\ResyncWhatsappTargets;
use App\Actions\Whatsapp\SetWhatsappAccountProxy;
use App\Actions\Whatsapp\SyncWhatsappConversations;
use App\Events\NotificationCreated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The user's own WhatsApp numbers (US-7.1, US-7.2): nickname, number,
 * status and last sync, with "Connect a number", "Reconnect" for a number
 * that is not connected and "Remove" (RemoveWhatsappAccount). Each account
 * also has "Rename" (RenameWhatsappAccount), "Check connection"
 * (RefreshWhatsappConnectionStatus) and, while connected, "Sync contacts
 * and groups" (ResyncWhatsappTargets), "Sync conversations"
 * (SyncWhatsappConversations, for the reply keyboard) and "Restart
 * connection" (RestartWhatsappConnection), and "Proxy" (SetWhatsappAccountProxy)
 * for the IP the number goes out with. Nobody else's accounts show, the super
 * admin's included.
 *
 * The statuses and the last sync follow without a refresh: the list reloads
 * on `WhatsappAccountStatusChanged` and `WhatsappTargetsSynced` on the
 * user's private channel.
 *
 * @property-read Collection<int, WhatsappAccount> $accounts
 */
#[Layout('layouts::app-shell')]
class AccountList extends Component
{
    #[Locked]
    public int $userId = 0;

    public ?string $status = null;

    public ?string $error = null;

    public bool $showRename = false;

    /**
     * The friendly id of the account being renamed.
     */
    #[Locked]
    public ?string $renamingId = null;

    public string $nickname = '';

    public bool $showProxy = false;

    /**
     * The friendly id of the account whose proxy is being set.
     */
    #[Locked]
    public ?string $proxyAccountId = null;

    /**
     * The proxy form; `password` blank keeps the saved one.
     *
     * @var array{enabled: bool, protocol: string, host: string, port: string, username: string, password: string}
     */
    public array $proxy = ['enabled' => false, 'protocol' => 'socks5', 'host' => '', 'port' => '', 'username' => '', 'password' => ''];

    public function mount(): void
    {
        $this->userId = $this->user()->id;
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $channel = 'echo-private:'.NotificationCreated::channelFor($this->userId);

        return [
            "{$channel},.whatsapp.account.status-changed" => 'refreshAccounts',
            "{$channel},.whatsapp.account.synced" => 'refreshAccounts',
        ];
    }

    /**
     * @return Collection<int, WhatsappAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->user()->whatsappAccounts()
            ->with('status')
            ->withCount('targets')
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();
    }

    public function refreshAccounts(): void
    {
        unset($this->accounts);
    }

    public function startRename(string $accountId): void
    {
        $account = $this->findAccount($accountId);

        $this->authorize('use', $account);

        $this->resetErrorBag();
        $this->renamingId = $account->friendly_id;
        $this->nickname = $account->nickname;
        $this->showRename = true;
    }

    public function rename(RenameWhatsappAccount $renameAccount): void
    {
        if ($this->renamingId === null) {
            return;
        }

        $this->resetErrorBag();

        $account = $renameAccount($this->user(), $this->findAccount($this->renamingId), $this->nickname);

        $this->status = __('Renamed to :nickname.', ['nickname' => $account->nickname]);
        $this->error = null;
        $this->reset('showRename', 'renamingId', 'nickname');
        unset($this->accounts);
    }

    public function startProxy(string $accountId): void
    {
        $account = $this->findAccount($accountId);

        $this->authorize('use', $account);

        $this->resetErrorBag();
        $this->proxyAccountId = $account->friendly_id;
        $this->proxy = [
            'enabled' => $account->hasProxy(),
            'protocol' => $account->proxy_protocol ?? 'socks5',
            'host' => (string) $account->proxy_host,
            'port' => (string) $account->proxy_port,
            'username' => (string) $account->proxy_username,
            'password' => '',
        ];
        $this->showProxy = true;
    }

    public function saveProxy(SetWhatsappAccountProxy $setProxy): void
    {
        if ($this->proxyAccountId === null) {
            return;
        }

        $this->resetErrorBag();
        $this->clearMessages();

        try {
            $account = $setProxy($this->user(), $this->findAccount($this->proxyAccountId), $this->proxy);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->addError('proxy.host', __('WhatsApp could not be reached. Try again in a moment.'));

            return;
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ["proxy.{$field}" => $messages])->all(),
            );
        }

        $this->status = $account->hasProxy()
            ? __(':nickname now goes out through :host.', ['nickname' => $account->nickname, 'host' => $account->proxy_host.':'.$account->proxy_port])
            : __(':nickname now goes out directly, without a proxy.', ['nickname' => $account->nickname]);
        $this->reset('showProxy', 'proxyAccountId', 'proxy');
        unset($this->accounts);
    }

    public function resync(string $accountId, ResyncWhatsappTargets $resyncTargets): void
    {
        $account = $this->findAccount($accountId);

        $this->clearMessages();

        if (! $resyncTargets($this->user(), $account)) {
            $this->error = __(':nickname is not connected, so there is nothing to sync.', ['nickname' => $account->nickname]);

            return;
        }

        $this->status = __('Syncing the contacts and groups of :nickname. This takes a moment.', ['nickname' => $account->nickname]);
    }

    public function syncConversations(string $accountId, SyncWhatsappConversations $syncConversations): void
    {
        $account = $this->findAccount($accountId);

        $this->clearMessages();

        if (! $syncConversations($this->user(), $account)) {
            $this->error = __(':nickname is not connected, so there is nothing to sync.', ['nickname' => $account->nickname]);

            return;
        }

        $this->status = __('Syncing the conversations of :nickname. Older history only comes the next time the number is linked.', ['nickname' => $account->nickname]);
    }

    public function restart(string $accountId, RestartWhatsappConnection $restartConnection): void
    {
        $account = $this->findAccount($accountId);

        $this->clearMessages();

        try {
            $restarted = $restartConnection($this->user(), $account);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');

            return;
        }

        if (! $restarted) {
            $this->error = __(':nickname is not connected. Reconnect it instead.', ['nickname' => $account->nickname]);

            return;
        }

        $this->status = __('Restarting the connection of :nickname. It is back in a few seconds.', ['nickname' => $account->nickname]);
    }

    public function checkConnection(string $accountId, RefreshWhatsappConnectionStatus $refreshStatus): void
    {
        $account = $this->findAccount($accountId);

        $this->clearMessages();

        try {
            $status = $refreshStatus($this->user(), $account);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached. Try again in a moment.');

            return;
        }

        $this->status = match ($status) {
            WhatsappConnectionStatus::OPEN => __(':nickname is connected.', ['nickname' => $account->nickname]),
            WhatsappConnectionStatus::CONNECTING => __(':nickname is still connecting.', ['nickname' => $account->nickname]),
            default => __(':nickname is not connected. Reconnect it to use it again.', ['nickname' => $account->nickname]),
        };
        unset($this->accounts);
    }

    public function remove(string $accountId, RemoveWhatsappAccount $removeAccount): void
    {
        $account = WhatsappAccount::query()->where('friendly_id', $accountId)->firstOrFail();

        $this->error = null;

        try {
            $removeAccount($this->user(), $account);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp could not be reached, so :nickname was kept. Try again in a moment.', ['nickname' => $account->nickname]);

            return;
        }

        $this->status = __(':nickname was removed.', ['nickname' => $account->nickname]);
        unset($this->accounts);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.account-list')->title(__('WhatsApp accounts'));
    }

    private function findAccount(string $accountId): WhatsappAccount
    {
        return WhatsappAccount::query()->where('friendly_id', $accountId)->firstOrFail();
    }

    private function clearMessages(): void
    {
        $this->status = null;
        $this->error = null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
