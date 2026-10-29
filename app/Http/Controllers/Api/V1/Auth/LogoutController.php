<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RevokeCurrentToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `POST /api/v1/auth/logout` (US-1.5): revokes only the token of this call.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request, RevokeCurrentToken $revokeCurrentToken): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revokeCurrentToken($user->currentAccessToken());

        return response()->noContent();
    }
}
