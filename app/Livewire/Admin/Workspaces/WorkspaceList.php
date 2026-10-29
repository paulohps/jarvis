<?php

namespace App\Livewire\Admin\Workspaces;

use App\Actions\Workspaces\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The workspaces of the admin area: the form that creates one (a name and a
 * color of Workspace::COLORS) and every workspace by name with its color and
 * how many members it has. A new workspace opens its page
 * (WorkspaceDetail), where it is renamed, recolored, deleted and given its
 * members and their modules.
 *
 * @property-read Collection<int, Workspace> $workspaces
 */
#[Layout('layouts::app-shell')]
class WorkspaceList extends Component
{
    public string $name = '';

    public string $color = 'blue';

    /**
     * What the last change did, e.g. a workspace deleted on its page.
     */
    public ?string $status = null;

    public function mount(): void
    {
        Gate::forUser($this->actor())->authorize('super-admin');

        $status = session('status');
        $this->status = is_string($status) ? $status : null;
    }

    /**
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function workspaces(): Collection
    {
        return Workspace::query()
            ->withCount('memberships')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function create(CreateWorkspace $createWorkspace): void
    {
        $workspace = $createWorkspace($this->actor(), [
            'name' => $this->name,
            'color' => $this->color,
        ]);

        $this->redirectRoute('admin.workspaces.show', ['workspace' => $workspace], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.workspaces.workspace-list')->title(__('Workspaces'));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
