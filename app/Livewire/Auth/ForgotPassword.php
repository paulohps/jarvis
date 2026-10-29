<?php

namespace App\Livewire\Auth;

use App\Actions\Auth\SendPasswordResetLink;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Asks for a password reset link (US-1.4). The same message follows every
 * request, so the screen never tells whether an address has an account.
 */
#[Layout('layouts::guest')]
class ForgotPassword extends Component
{
    public string $email = '';

    public ?string $status = null;

    public function sendLink(SendPasswordResetLink $sendPasswordResetLink): void
    {
        $this->status = $sendPasswordResetLink(['email' => $this->email]);
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password')->title(__('Forgot your password?'));
    }
}
