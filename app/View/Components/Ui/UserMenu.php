<?php

namespace App\View\Components\Ui;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

/**
 * The signed-in user's avatar with a menu: name and e-mail, then Profile and
 * Sign out once their routes (`profile`, `logout`) exist.
 *
 * Props:
 * - `expanded`: also shows the name beside the avatar (sidebar).
 *
 * Slots: none. Renders nothing for a guest.
 */
class UserMenu extends Component
{
    public ?User $user;

    public function __construct(
        public bool $expanded = false,
    ) {
        $user = Auth::user();

        $this->user = $user instanceof User ? $user : null;
    }

    public function shouldRender(): bool
    {
        return $this->user !== null;
    }

    public function render(): View
    {
        return view('components.ui.user-menu');
    }
}
