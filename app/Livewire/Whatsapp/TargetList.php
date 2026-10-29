<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\AddWhatsappTarget;
use App\Actions\Whatsapp\RemoveWhatsappTarget;
use App\Actions\Whatsapp\RenameWhatsappTarget;
use App\Actions\Whatsapp\ResyncWhatsappTargets;
use App\Events\NotificationCreated;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Models\WhatsappTargetType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The contacts and groups of one of the user's accounts, 50 per page, by
 * name: a tab for each, a search by name, number or id (kept in the URL),
 * and which ones were added by hand. "Sync contacts and groups" queues a
 * sync of a connected account (ResyncWhatsappTargets). "Add contact" /
 * "Add group" adds one by hand (AddWhatsappTarget; a group by its invite
 * link, joining it, or by its id); one added by hand can be renamed
 * (RenameWhatsappTarget) and removed while no message uses it
 * (RemoveWhatsappTarget). The list follows the syncs without a refresh
 * (`WhatsappTargetsSynced`).
 *
 * Only the owner opens it.
 *
 * @property-read WhatsappAccount $account
 * @property-read LengthAwarePaginator<int, WhatsappTarget> $targets
 */
#[Layout('layouts::app-shell')]
class TargetList extends Component
{
    use WithPagination;

    public const int PER_PAGE = 50;

    #[Locked]
    public string $accountId = '';

    #[Locked]
    public int $userId = 0;

    #[Url(except: WhatsappTargetType::CONTACT)]
    public string $type = WhatsappTargetType::CONTACT;

    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    /**
     * The friendly id of the target being renamed; null while adding.
     */
    #[Locked]
    public ?string $editingId = null;

    public string $identifier = '';

    public string $name = '';

    /**
     * Whether the number joins the group of an invite link being added.
     */
    public bool $joinGroup = true;

    public ?string $status = null;

    public ?string $error = null;

    public function mount(WhatsappAccount $account): void
    {
        $this->authorize('use', $account);

        $this->accountId = $account->friendly_id;
        $this->userId = $this->user()->id;

        if (! in_array($this->type, [WhatsappTargetType::CONTACT, WhatsappTargetType::GROUP], true)) {
            $this->type = WhatsappTargetType::CONTACT;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo-private:'.NotificationCreated::channelFor($this->userId).',.whatsapp.account.synced' => 'refreshTargets',
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['type', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function account(): WhatsappAccount
    {
        return $this->user()->whatsappAccounts()->where('friendly_id', $this->accountId)->firstOrFail();
    }

    /**
     * @return LengthAwarePaginator<int, WhatsappTarget>
     */
    #[Computed]
    public function targets(): LengthAwarePaginator
    {
        $search = mb_trim($this->search);

        return $this->account->targets()
            ->where('whatsapp_target_type_id', WhatsappTargetType::idFor($this->type))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $digits = (string) preg_replace('/\D/', '', $search);

                $query->where(function (Builder $query) use ($term, $digits): void {
                    $query->whereRaw('lower(name) like ?', [$term]);

                    if ($digits !== '') {
                        $query->orWhere('jid', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByRaw('name is null')
            ->orderBy('name')
            ->orderBy('jid')
            ->paginate(self::PER_PAGE);
    }

    public function isGroups(): bool
    {
        return $this->type === WhatsappTargetType::GROUP;
    }

    public function refreshTargets(): void
    {
        unset($this->account, $this->targets);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $targetId): void
    {
        $target = $this->findTarget($targetId);

        $this->resetForm();
        $this->editingId = $target->friendly_id;
        $this->identifier = $target->phoneNumber() ?? $target->jid;
        $this->name = (string) $target->name;
        $this->showForm = true;
    }

    public function resync(ResyncWhatsappTargets $resyncTargets): void
    {
        $this->status = null;
        $this->error = null;

        if (! $resyncTargets($this->user(), $this->account)) {
            $this->error = __(':nickname is not connected, so there is nothing to sync.', ['nickname' => $this->account->nickname]);

            return;
        }

        $this->status = __('Syncing the contacts and groups of :nickname. This takes a moment.', ['nickname' => $this->account->nickname]);
    }

    public function save(AddWhatsappTarget $addTarget, RenameWhatsappTarget $renameTarget): void
    {
        $this->resetErrorBag();

        try {
            $target = $this->editingId === null
                ? $addTarget($this->user(), $this->account, $this->type, $this->identifier, $this->name, $this->joinGroup)
                : $renameTarget($this->user(), $this->findTarget($this->editingId), $this->name);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->addError('identifier', __('WhatsApp could not be reached. Try again in a moment.'));

            return;
        }

        $this->status = $this->editingId === null
            ? __(':name was added.', ['name' => $target->displayName()])
            : __(':name was saved.', ['name' => $target->displayName()]);
        $this->error = null;
        $this->resetForm();
        unset($this->targets);
    }

    public function remove(string $targetId, RemoveWhatsappTarget $removeTarget): void
    {
        $target = $this->findTarget($targetId);

        $this->status = null;
        $this->error = null;

        try {
            $removeTarget($this->user(), $target);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->status = __(':name was removed.', ['name' => $target->displayName()]);
        unset($this->targets);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.target-list')
            ->title(__('Contacts and groups of :nickname', ['nickname' => $this->account->nickname]));
    }

    private function findTarget(string $targetId): WhatsappTarget
    {
        return $this->account->targets()->where('friendly_id', $targetId)->firstOrFail();
    }

    private function resetForm(): void
    {
        $this->resetErrorBag();
        $this->reset('showForm', 'editingId', 'identifier', 'name', 'joinGroup');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
