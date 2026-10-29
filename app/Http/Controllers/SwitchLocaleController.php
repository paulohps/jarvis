<?php

namespace App\Http\Controllers;

use App\Support\Localization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The visitor's language switch (guest layout): keeps the choice in the
 * session and goes back to the page it came from. A signed-in user's saved
 * preference still wins (US-3.2).
 */
class SwitchLocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(Localization::isSupported($locale), 404);

        $request->session()->put(Localization::SESSION_KEY, $locale);

        return redirect()->back(fallback: route('login'));
    }
}
