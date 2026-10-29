<?php

namespace App\Livewire\Admin\Workspaces;

use App\Actions\Workspaces\AddWorkspaceMember;
use App\Actions\Workspaces\DeleteWorkspace;
use App\Actions\Workspaces\RemoveWorkspaceMember;
use App\Actions\Workspaces\SetMemberChatProviders;
use App\Actions\Workspaces\SetMemberModules;
use App\Actions\Workspaces\SetWorkspaceChatProviders;
use App\Actions\Workspaces\UpdateWorkspace;
use App\Actions\Workspaces\UpdateWorkspaceMember;
use App\Models\ChatProvider;
use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Navigation\AppNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One workspace in the admin area: its name and color (UpdateWorkspace), its
 * deletion — refused while it has data (DeleteWorkspace) — and its members.
 *
 * Each member shows a switch per module, saved as it changes
 * (SetMemberModules: a module the user had in no workspace announces itself
 * with `module_enabled`), the color they see the workspace in and whether it
 * is their default (UpdateWorkspaceMember), and can be removed
 * (RemoveWorkspaceMember). The super admin has every module and shows no
 * switches. Any user not yet a member can be added with their modules
 * (AddWorkspaceMember).
 *
 * The chat channels (ChatProvider) are switched on per workspace
 * (SetWorkspaceChatProviders) and, for each member with the `chats`
 * module, per member (SetMemberChatProviders): a member uses a channel
 * only when both are on.
 *
 * @property-read Collection<int, WorkspaceMembership> $memberships
 * @property-read Collection<int, Module> $moduleColumns
 * @property-read Collection<int, ChatProvider> $providerColumns
 * @property-read Collection<int, User> $candidates
 */
#[Layout('layouts::app-shell')]
class WorkspaceDetail extends Component
{
    #[Locked]
    public Workspace $workspace;

    public string $name = '';

    public string $color = '';

    /**
     * @var array<int|string, array<string, bool>> membership id => module slug => switched on
     */
    public array $access = [];

    /**
     * @var array<int|string, string> membership id => own color, '' for the workspace's
     */
    public array $memberColors = [];

    /**
     * @var array<string, bool> chat provider slug => switched on for the workspace
     */
    public array $workspaceChannels = [];

    /**
     * @var array<int|string, array<string, bool>> membership id => chat provider slug => switched on
     */
    public array $memberChannels = [];

    /**
     * The friendly id of the user to add.
     */
    public string $newMember = '';

    /**
     * @var array<int|string, string> slugs of the modules the new member gets
     */
    public array $newMemberModules = [];

    public ?string $status = null;

    public function mount(Workspace $workspace): void
    {
        Gate::forUser($this->actor())->authorize('super-admin');

        $this->workspace = $workspace;
        $this->name = $workspace->name;
        $this->color = $workspace->color;

        $this->readMembers();
    }

    /**
     * The members, by name, with their modules. Deleted users are left out.
     *
     * @return Collection<int, WorkspaceMembership>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return $this->workspace->memberships()
            ->with(['user', 'modules', 'chatProviders'])
            ->get()
            ->filter(fn (WorkspaceMembership $membership): bool => $membership->user !== null)
            ->sortBy(fn (WorkspaceMembership $membership): string => mb_strtolower($membership->user->name))
            ->values();
    }

    /**
     * The active modules, in menu order.
     *
     * @return Collection<int, Module>
     */
    #[Computed]
    public function moduleColumns(): Collection
    {
        return Module::query()->active()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * The active chat channels, in menu order.
     *
     * @return Collection<int, ChatProvider>
     */
    #[Computed]
    public function providerColumns(): Collection
    {
        return ChatProvider::query()->active()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * The users who are not members yet, by name.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function candidates(): Collection
    {
        return User::query()
            ->whereNotIn('id', WorkspaceMembership::query()->where('workspace_id', $this->workspace->id)->select('user_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function moduleLabel(Module $module): string
    {
        return AppNavigation::moduleLabel($module);
    }

    public function save(UpdateWorkspace $updateWorkspace): void
    {
        $this->resetValidation();

        $this->workspace = $updateWorkspace($this->actor(), $this->workspace, [
            'name' => $this->name,
            'color' => $this->color,
        ]);

        $this->name = $this->workspace->name;
        $this->status = __('Workspace saved.');
    }

    public function delete(DeleteWorkspace $deleteWorkspace): void
    {
        $this->resetValidation();

        $name = $this->workspace->name;

        $deleteWorkspace($this->actor(), $this->workspace);

        session()->flash('status', __(':name was deleted.', ['name' => $name]));

        $this->redirectRoute('admin.workspaces.index', navigate: true);
    }

    /**
     * Saves one module switch: `$path` is "<membership id>.<module slug>".
     * The member gets exactly the switches that are on.
     */
    public function updatedAccess(mixed $value, ?string $path = null): void
    {
        [$membershipId] = explode('.', (string) $path, 2);

        $membership = $this->membership((int) $membershipId);

        $slugs = array_keys(array_filter(
            $this->access[$membership->id] ?? [],
            fn (mixed $isOn): bool => filter_var($isOn, FILTER_VALIDATE_BOOLEAN),
        ));

        try {
            app(SetMemberModules::class)($this->actor(), $membership, array_values($slugs));
        } finally {
            $this->refreshMembers();
        }
    }

    /**
     * Saves one chat channel switch of the workspace: `$path` is the
     * provider slug. The workspace gets exactly the switches that are on.
     */
    public function updatedWorkspaceChannels(mixed $value, ?string $path = null): void
    {
        $slugs = array_keys(array_filter($this->workspaceChannels, fn (mixed $isOn): bool => filter_var($isOn, FILTER_VALIDATE_BOOLEAN)));

        try {
            app(SetWorkspaceChatProviders::class)($this->actor(), $this->workspace, $slugs);
        } finally {
            $this->refreshMembers();
        }
    }

    /**
     * Saves one chat channel switch of a member: `$path` is
     * "<membership id>.<provider slug>".
     */
    public function updatedMemberChannels(mixed $value, ?string $path = null): void
    {
        [$membershipId] = explode('.', (string) $path, 2);

        $membership = $this->membership((int) $membershipId);

        $slugs = array_keys(array_filter(
            $this->memberChannels[$membership->id] ?? [],
            fn (mixed $isOn): bool => filter_var($isOn, FILTER_VALIDATE_BOOLEAN),
        ));

        try {
            app(SetMemberChatProviders::class)($this->actor(), $membership, $slugs);
        } finally {
            $this->refreshMembers();
        }
    }

    /**
     * Saves a member's own color: `$path` is the membership id, '' goes back
     * to the workspace's color.
     */
    public function updatedMemberColors(mixed $value, ?string $path = null): void
    {
        $membership = $this->membership((int) $path);

        try {
            app(UpdateWorkspaceMember::class)($this->actor(), $membership, [
                'color' => is_string($value) && $value !== '' ? $value : null,
            ]);
        } finally {
            $this->refreshMembers();
        }
    }

    public function makeDefault(int $membershipId, UpdateWorkspaceMember $updateWorkspaceMember): void
    {
        $membership = $this->membership($membershipId);

        $updateWorkspaceMember($this->actor(), $membership, ['is_default' => true]);

        $this->status = __(':workspace is now the default of :name.', ['workspace' => $this->workspace->name, 'name' => $membership->user->name]);
        $this->refreshMembers();
    }

    public function addMember(AddWorkspaceMember $addWorkspaceMember): void
    {
        $this->resetValidation();

        $user = $this->candidates->firstWhere('friendly_id', $this->newMember);

        if (! $user instanceof User) {
            throw ValidationException::withMessages(['newMember' => __('Choose a user.')]);
        }

        $addWorkspaceMember($this->actor(), $this->workspace, $user, [
            'modules' => array_values($this->newMemberModules),
        ]);

        $this->reset('newMember', 'newMemberModules');
        $this->status = __(':name was added.', ['name' => $user->name]);
        $this->refreshMembers();
    }

    public function removeMember(int $membershipId, RemoveWorkspaceMember $removeWorkspaceMember): void
    {
        $membership = $this->membership($membershipId);

        $removeWorkspaceMember($this->actor(), $membership);

        $this->status = __(':name was removed.', ['name' => $membership->user->name]);
        $this->refreshMembers();
    }

    public function render(): View
    {
        return view('livewire.admin.workspaces.workspace-detail')->title($this->workspace->name);
    }

    /**
     * A membership of this workspace, 404 for any other.
     */
    private function membership(int $membershipId): WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspace->id)
            ->with(['user', 'workspace'])
            ->findOrFail($membershipId);
    }

    private function refreshMembers(): void
    {
        unset($this->memberships, $this->candidates);

        $this->readMembers();
    }

    /**
     * Fills the switches and colors from the memberships.
     */
    private function readMembers(): void
    {
        $this->access = [];
        $this->memberColors = [];
        $this->workspaceChannels = [];
        $this->memberChannels = [];

        $this->workspace->unsetRelation('chatProviders');

        foreach ($this->providerColumns as $provider) {
            $this->workspaceChannels[$provider->slug] = $this->workspace->chatProviders->contains('id', $provider->id);
        }

        foreach ($this->memberships as $membership) {
            $this->memberColors[$membership->id] = (string) $membership->color;

            if ($membership->user->isSuperAdmin()) {
                continue;
            }

            foreach ($this->moduleColumns as $module) {
                $this->access[$membership->id][$module->slug] = $membership->modules->contains('id', $module->id);
            }

            foreach ($this->providerColumns as $provider) {
                $this->memberChannels[$membership->id][$provider->slug] = $membership->chatProviders->contains('id', $provider->id);
            }
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
