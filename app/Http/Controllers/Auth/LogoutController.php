<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LogoutUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "Sign out" of the user menu: ends the session and opens the sign-in
 * screen.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutUser $logoutUser): RedirectResponse
    {
        $logoutUser($request);

        return redirect()->route('login');
    }
}
