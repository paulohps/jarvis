<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\AuthenticateUser;
use App\Http\Controllers\Auth\GoogleCallbackController;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The sign-in screen (US-1.4, US-1.3): e-mail and password, or Google.
 * There is no sign-up: accounts only come from invitations.
 */
#[Layout('layouts::guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login(AuthenticateUser $authenticateUser): void
    {
        $authenticateUser([
            'email' => $this->email,
            'password' => $this->password,
            'remember' => $this->remember,
        ], (string) request()->ip());

        $this->redirectIntended(default: route('dashboard'));
    }

    public function render(): View
    {
        return view('livewire.auth.login', [
            'status' => Session::get('status'),
            'googleError' => Session::get(GoogleCallbackController::ERROR_KEY),
        ])->title(__('Sign in'));
    }
}
