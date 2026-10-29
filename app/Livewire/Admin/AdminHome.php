<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Support\Navigation\AppNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The start page of the admin area (`/admin`): one entry per admin screen.
 */
#[Layout('layouts::app-shell')]
class AdminHome extends Component
{
    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();

        Gate::forUser($user)->authorize('super-admin');
    }

    /**
     * The admin screens gathered as the sidebar gathers them
     * (AppNavigation::adminGroups()): each group's title and its screens,
     * route name => [title, subtitle, icon].
     *
     * @return list<array{title: string, sections: array<string, array{0: string, 1: string, 2: string}>}>
     */
    public function groups(): array
    {
        $sections = AppNavigation::adminSections();

        return array_map(fn (array $group): array => [
            'title' => $group['title'],
            'sections' => collect($group['routes'])
                ->mapWithKeys(fn (string $route): array => [$route => [$sections[$route]['title'], $sections[$route]['subtitle'], $sections[$route]['icon']]])
                ->all(),
        ], AppNavigation::adminGroups());
    }

    public function render(): View
    {
        return view('livewire.admin.admin-home')->title(__('Admin'));
    }
}
