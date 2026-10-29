<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\ResetPassword;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The screen of the reset link (US-1.4): the new password, twice. On
 * success it opens the sign-in screen with the confirmation.
 */
#[Layout('layouts::guest')]
class ResetPasswordForm extends Component
{
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(ResetPassword $resetPassword): void
    {
        $status = $resetPassword([
            'token' => $this->token,
            'email' => $this->email,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        session()->flash('status', $status);

        $this->redirectRoute('login');
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password-form')->title(__('Choose a new password'));
    }
}
