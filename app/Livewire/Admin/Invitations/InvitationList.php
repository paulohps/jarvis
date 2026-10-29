<?php

namespace App\Livewire\Admin\Invitations;

use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Localization;
use App\Support\Navigation\AppNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The invitations of the admin area (US-1.1): the form that invites someone
 * (e-mail, language, the workspace they join — the super admin's default
 * one unless picked — and their modules there) and every invitation sent,
 * newest first, with its workspace and derived status. Only pending
 * invitations can be resent or revoked.
 *
 * @property-read Collection<int, Invitation> $invitations
 * @property-read array<string, string> $moduleOptions
 * @property-read Collection<int, Workspace> $workspaceOptions
 */
#[Layout('layouts::app-shell')]
class InvitationList extends Component
{
    public string $email = '';

    public string $locale = Localization::DEFAULT_LOCALE;

    /**
     * The friendly id of the workspace the invited user joins.
     */
    public string $workspace = '';

    /**
     * @var array<int|string, string> slugs of the modules switched on when accepting (the browser may send them keyed)
     */
    public array $modules = [];

    public ?string $status = null;

    public function mount(): void
    {
        Gate::forUser($this->actor())->authorize('viewAny', Invitation::class);

        $this->workspace = $this->defaultWorkspaceId();
    }

    /**
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return Invitation::query()
            ->with(['modules', 'locale', 'workspace'])
            ->latest('id')
            ->get();
    }

    /**
     * The active modules, slug => name in the reader's language.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function moduleOptions(): array
    {
        return Module::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Module $module): array => [$module->slug => AppNavigation::moduleLabel($module)])
            ->all();
    }

    /**
     * Every workspace, by name.
     *
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function workspaceOptions(): Collection
    {
        return Workspace::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The invitation's modules in menu order, or "No modules".
     */
    public function moduleNamesOf(Invitation $invitation): string
    {
        if ($invitation->modules->isEmpty()) {
            return __('No modules');
        }

        return $invitation->modules
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn (Module $module): string => AppNavigation::moduleLabel($module))
            ->join(', ');
    }

    public function invite(InviteUser $inviteUser): void
    {
        $invitation = $inviteUser($this->actor(), [
            'email' => $this->email,
            'workspace' => $this->workspace,
            'locale' => $this->locale,
            'modules' => array_values($this->modules),
        ]);

        $this->reset('email', 'modules');
        $this->locale = Localization::DEFAULT_LOCALE;
        $this->workspace = $this->defaultWorkspaceId();
        $this->status = __('Invitation sent to :email.', ['email' => $invitation->email]);

        unset($this->invitations);
    }

    public function resend(int $invitationId, ResendInvitation $resendInvitation): void
    {
        $invitation = $resendInvitation($this->actor(), Invitation::query()->findOrFail($invitationId));

        $this->status = __('Invitation sent to :email again.', ['email' => $invitation->email]);

        unset($this->invitations);
    }

    public function revoke(int $invitationId, RevokeInvitation $revokeInvitation): void
    {
        $invitation = $revokeInvitation($this->actor(), Invitation::query()->findOrFail($invitationId));

        $this->status = __('Invitation to :email revoked.', ['email' => $invitation->email]);

        unset($this->invitations);
    }

    public function render(): View
    {
        return view('livewire.admin.invitations.invitation-list', [
            'localeOptions' => Localization::NAMES,
        ])->title(__('Invitations'));
    }

    /**
     * The friendly id of the super admin's default workspace, else of the
     * first one by name; '' when there is none.
     */
    private function defaultWorkspaceId(): string
    {
        $actor = $this->actor();
        $actor->loadMissing('memberships.workspace');

        $workspace = ($actor->memberships->firstWhere('is_default', true) ?? $actor->memberships->first())?->workspace
            ?? $this->workspaceOptions->first();

        return (string) $workspace?->friendly_id;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
