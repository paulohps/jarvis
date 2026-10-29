<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\CreateWhatsappGroup;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "New group" dialog of the WhatsApp screen: a subject, an optional
 * description and members picked among the number's contacts
 * (CreateWhatsappGroup). Opened with `whatsapp-new-group`; once created,
 * the chat opens the group (`whatsapp-open-chat`).
 *
 * Only the user's numbers.
 *
 * @property-read WhatsappAccount $account
 * @property-read Collection<int, WhatsappTarget> $contacts
 */
class NewGroup extends Component
{
    public const int CONTACTS_SHOWN = 50;

    #[Locked]
    public string $accountId;

    public bool $show = false;

    public string $subject = '';

    public string $description = '';

    public string $search = '';

    /**
     * @var list<string>
     */
    public array $members = [];

    public function mount(string $accountId): void
    {
        $this->accountId = $accountId;
        $this->authorize('use', $this->account);
    }

    #[Computed]
    public function account(): WhatsappAccount
    {
        return WhatsappAccount::query()
            ->where('friendly_id', $this->accountId)
            ->where('user_id', $this->user()->id)
            ->firstOrFail();
    }

    /**
     * The number's contacts with a phone number, by name, the search
     * narrowing them; the ones picked first.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function contacts(): Collection
    {
        $search = mb_trim($this->search);

        return $this->account->targets()
            ->where('jid', 'like', '%'.WhatsappTarget::CONTACT_JID_SUFFIX)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereIn('friendly_id', $this->members)
                ->orWhereLike('name', '%'.$search.'%')
                ->orWhereLike('nickname', '%'.$search.'%')
                ->when(preg_replace('/\D/', '', $search) !== '', fn ($query) => $query->orWhereLike('jid', '%'.preg_replace('/\D/', '', $search).'%'))))
            ->orderByRaw('coalesce(nickname, name, jid)')
            ->limit(self::CONTACTS_SHOWN + count($this->members))
            ->get()
            ->sortByDesc(fn (WhatsappTarget $contact): bool => in_array($contact->friendly_id, $this->members, true))
            ->values();
    }

    #[On('whatsapp-new-group')]
    public function open(): void
    {
        $this->reset('subject', 'description', 'search', 'members');
        $this->resetErrorBag();
        $this->show = true;
    }

    public function create(CreateWhatsappGroup $createGroup): void
    {
        try {
            $group = $createGroup($this->user(), $this->account, $this->subject, $this->members, $this->description);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->addError('subject', __('WhatsApp did not create the group. Try again in a moment.'));

            return;
        }

        $this->show = false;
        $this->dispatch('whatsapp-open-chat', chatId: $group->friendly_id);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.new-group');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
