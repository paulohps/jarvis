<?php

namespace App\Livewire\Auth;

use App\Actions\Invitations\AcceptInvitation;
use App\Exceptions\InvalidInvitation;
use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The page of the invitation link (US-1.2, US-1.3): the invited e-mail,
 * locked, then a name and a password — or Google with that same e-mail. A
 * link that no longer works shows the invitation error page.
 */
#[Layout('layouts::guest')]
class AcceptInvitationForm extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * @throws InvalidInvitation
     */
    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = AcceptInvitation::pendingInvitation($token)->email;
    }

    public function accept(AcceptInvitation $acceptInvitation): void
    {
        try {
            $acceptInvitation($this->token, [
                'name' => $this->name,
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
            ]);
        } catch (InvalidInvitation) {
            // The page itself shows the error.
            $this->redirectRoute('invitations.accept', ['token' => $this->token]);

            return;
        }

        $this->redirectRoute('dashboard');
    }

    public function render(): View
    {
        return view('livewire.auth.accept-invitation-form', [
            'googleUrl' => route('auth.google.redirect', [GoogleRedirectController::INVITATION_PARAMETER => $this->token]),
            'googleError' => session(GoogleCallbackController::ERROR_KEY),
        ])->title(__('Accept the invitation'));
    }
}
