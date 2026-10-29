<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\BlockWhatsappContact;
use App\Actions\Whatsapp\ListWhatsappGroupParticipants;
use App\Actions\Whatsapp\ManageWhatsappGroup;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The "Details" tab of the side panel of an open chat. A contact: its
 * number, and blocking or unblocking it (BlockWhatsappContact). A group,
 * as WhatsApp has it now (loaded once the tab shows): its subject,
 * description and picture, its members (admins first) with adding,
 * removing, promoting and demoting them, who may send messages and edit
 * its info, disappearing messages, the invite link, and leaving it
 * (ManageWhatsappGroup). What only an admin may do is offered only when
 * the number is one. Tells the chat with `whatsapp-chat-changed`.
 *
 * Only the chats of the user's numbers.
 *
 * @property-read WhatsappTarget $chat
 * @property-read Collection<int, WhatsappTarget> $addableContacts
 */
class ChatDetails extends Component
{
    use WithFileUploads;

    public const int CONTACTS_SHOWN = 50;

    #[Locked]
    public string $chatId;

    public bool $loaded = false;

    /**
     * The group's members (ListWhatsappGroupParticipants).
     *
     * @var list<array{jid: string, lid: string|null, name: string, number: string|null, admin: bool, me: bool}>
     */
    #[Locked]
    public array $members = [];

    public bool $adminsOnly = false;

    public bool $infoAdminsOnly = false;

    public string $subject = '';

    public string $description = '';

    public bool $editingInfo = false;

    public ?TemporaryUploadedFile $picture = null;

    public string $ephemeral = '0';

    #[Locked]
    public ?string $inviteLink = null;

    public bool $showAddMembers = false;

    public string $memberSearch = '';

    /**
     * @var list<string>
     */
    public array $pickedMembers = [];

    public string $memberNumber = '';

    public bool $showLeave = false;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(string $chatId): void
    {
        $this->chatId = $chatId;
        $this->authorize('use', $this->chat->account);
        $this->ephemeral = (string) $this->chat->ephemeral_seconds;
    }

    #[Computed]
    public function chat(): WhatsappTarget
    {
        return WhatsappTarget::query()
            ->where('friendly_id', $this->chatId)
            ->whereHas('account', fn ($query) => $query->where('user_id', $this->user()->id))
            ->with('account')
            ->firstOrFail();
    }

    /**
     * Whether the number is an admin of the group.
     */
    #[Computed]
    public function isAdmin(): bool
    {
        return collect($this->members)->contains(fn (array $member): bool => $member['me'] && $member['admin']);
    }

    /**
     * The number's contacts not in the group yet, by name, the search
     * narrowing them.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function addableContacts(): Collection
    {
        $search = mb_trim($this->memberSearch);

        return $this->chat->account->targets()
            ->where('jid', 'like', '%'.WhatsappTarget::CONTACT_JID_SUFFIX)
            ->whereNotIn('jid', array_column($this->members, 'jid'))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('nickname', '%'.$search.'%')
                ->when(preg_replace('/\D/', '', $search) !== '', fn ($query) => $query->orWhereLike('jid', '%'.preg_replace('/\D/', '', $search).'%'))))
            ->orderByRaw('coalesce(nickname, name, jid)')
            ->limit(self::CONTACTS_SHOWN)
            ->get();
    }

    /**
     * Brings the group's info and members from WhatsApp, once the tab shows.
     */
    public function load(ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): void
    {
        if (! $this->chat->isGroup() || ! $this->chat->account->isOpen()) {
            $this->loaded = true;

            return;
        }

        $this->attempt(function () use ($manage, $listParticipants): void {
            $permissions = $manage->refresh($this->user(), $this->chat);
            $this->adminsOnly = $permissions['announce'];
            $this->infoAdminsOnly = $permissions['restrict'];
            $this->members = $listParticipants($this->user(), $this->chat);
        });

        unset($this->chat, $this->isAdmin);
        $this->subject = (string) $this->chat->name;
        $this->description = (string) $this->chat->description;
        $this->ephemeral = (string) $this->chat->ephemeral_seconds;
        $this->loaded = true;
    }

    public function toggleBlock(BlockWhatsappContact $block): void
    {
        $this->attempt(fn () => $block($this->user(), $this->chat, ! $this->chat->isBlocked()));
        $this->changed();
    }

    public function editInfo(): void
    {
        $this->subject = (string) $this->chat->name;
        $this->description = (string) $this->chat->description;
        $this->editingInfo = true;
    }

    public function saveInfo(ManageWhatsappGroup $manage): void
    {
        $this->attempt(function () use ($manage): void {
            if ($this->subject !== (string) $this->chat->name) {
                $manage->rename($this->user(), $this->chat, $this->subject);
            }

            if ($this->description !== (string) $this->chat->description) {
                $manage->describe($this->user(), $this->chat, $this->description);
            }

            $this->editingInfo = false;
        });

        $this->changed();
    }

    public function updatedPicture(ManageWhatsappGroup $manage): void
    {
        if ($this->picture === null) {
            return;
        }

        $this->attempt(fn () => $manage->changePicture($this->user(), $this->chat, $this->picture), __('Picture changed. It shows once WhatsApp hands it over.'));
        $this->reset('picture');
        $this->changed();
    }

    public function updatedEphemeral(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $manage->setTimer($this->user(), $this->chat, (int) $this->ephemeral));
        $this->ephemeral = (string) $this->chat->refresh()->ephemeral_seconds;
        $this->changed();
    }

    public function updatedAdminsOnly(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $manage->setPermissions($this->user(), $this->chat, $this->adminsOnly, null));
    }

    public function updatedInfoAdminsOnly(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $manage->setPermissions($this->user(), $this->chat, null, $this->infoAdminsOnly));
    }

    /**
     * Removes, promotes or demotes one member.
     */
    public function updateMember(string $jid, string $action, ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): void
    {
        if (! in_array($jid, array_column($this->members, 'jid'), true) || ! in_array($action, ['remove', 'promote', 'demote'], true)) {
            return;
        }

        $this->attempt(function () use ($jid, $action, $manage, $listParticipants): void {
            $done = $manage->updateMembers($this->user(), $this->chat, $action, [$jid]);
            $this->notice = in_array(false, $done, true) ? null : __('Done.');
            $this->error = in_array(false, $done, true) ? __('WhatsApp did not take it for everyone.') : null;
            $this->members = $listParticipants($this->user(), $this->chat);
        });

        unset($this->isAdmin);
    }

    public function startAddMembers(): void
    {
        $this->reset('memberSearch', 'pickedMembers', 'memberNumber');
        $this->resetErrorBag();
        $this->showAddMembers = true;
    }

    public function addMembers(ManageWhatsappGroup $manage, ListWhatsappGroupParticipants $listParticipants): void
    {
        $participants = $this->chat->account->targets()->whereIn('friendly_id', $this->pickedMembers)->pluck('jid')->all();

        if (filled($this->memberNumber)) {
            $participants[] = (string) preg_replace('/\D/', '', $this->memberNumber);
        }

        try {
            $done = $manage->updateMembers($this->user(), $this->chat, 'add', $participants);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['memberNumber' => collect($exception->errors())->flatten()->first()]);
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->addError('memberNumber', __('WhatsApp did not take it. Try again in a moment.'));

            return;
        }

        $failed = count(array_filter($done, fn (bool $added): bool => ! $added));
        $this->notice = $failed === 0 ? __('Added.') : null;
        $this->error = $failed > 0 ? trans_choice('{1} WhatsApp did not add 1 of them (their privacy may only allow invites).|[2,*] WhatsApp did not add :count of them (their privacy may only allow invites).', $failed, ['count' => $failed]) : null;
        $this->attempt(fn () => $this->members = $listParticipants($this->user(), $this->chat));
        $this->showAddMembers = false;
        unset($this->isAdmin, $this->addableContacts);
    }

    public function showInviteLink(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $this->inviteLink = $manage->inviteLink($this->user(), $this->chat));
    }

    public function revokeInviteLink(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $this->inviteLink = $manage->inviteLink($this->user(), $this->chat, revoke: true), __('A new link was made; the old one no longer works.'));
    }

    public function leave(ManageWhatsappGroup $manage): void
    {
        $this->attempt(fn () => $manage->leave($this->user(), $this->chat), __('You left the group.'));
        $this->showLeave = false;
        $this->members = [];
        $this->changed();
    }

    public function render(): View
    {
        return view('livewire.whatsapp.chat-details');
    }

    /**
     * Runs a change on WhatsApp, telling what went wrong in the panel.
     */
    private function attempt(callable $change, ?string $done = null): void
    {
        $this->notice = null;
        $this->error = null;

        try {
            $change();
            $this->notice = $done ?? $this->notice;
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();
        } catch (EvolutionApiException $exception) {
            report($exception);
            $this->error = __('WhatsApp did not take it. Only an admin may do this, or WhatsApp could not be reached.');
        }
    }

    private function changed(): void
    {
        unset($this->chat);
        $this->dispatch('whatsapp-chat-changed');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
