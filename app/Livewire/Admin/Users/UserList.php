<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The users of the admin area (US-2.2): name, e-mail, last access and
 * workspaces of each one (a badge in the workspace's color, opening its
 * page, where the modules are set), the super admin first. Every other user can be
 * deactivated (signed out everywhere, data kept) and reactivated.
 *
 * @property-read Collection<int, User> $users
 */
#[Layout('layouts::app-shell')]
class UserList extends Component
{
    public ?string $status = null;

    public function mount(): void
    {
        Gate::forUser($this->actor())->authorize('super-admin');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function users(): Collection
    {
        return User::query()
            ->with('memberships.workspace')
            ->orderByDesc('is_super_admin')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function deactivate(int $userId, DeactivateUser $deactivateUser): void
    {
        $user = $deactivateUser($this->actor(), User::query()->findOrFail($userId));

        $this->status = __(':name was deactivated.', ['name' => $user->name]);

        unset($this->users);
    }

    public function reactivate(int $userId, ReactivateUser $reactivateUser): void
    {
        $user = $reactivateUser($this->actor(), User::query()->findOrFail($userId));

        $this->status = __(':name was reactivated.', ['name' => $user->name]);

        unset($this->users);
    }

    public function render(): View
    {
        return view('livewire.admin.users.user-list')->title(__('Users'));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
