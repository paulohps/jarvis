<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateApiDeviceWithGoogle;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/google` (US-1.5, US-1.3): the ID token of the native
 * Google Sign-In and `device_name` give back `{ token, user }`; a refusal is
 * a 422 with the reason.
 */
class GoogleLoginController extends Controller
{
    public function __invoke(Request $request, AuthenticateApiDeviceWithGoogle $authenticateWithGoogle): JsonResponse
    {
        $token = $authenticateWithGoogle($request->only(['id_token', 'device_name']));

        /** @var User $user */
        $user = $token->accessToken->tokenable;

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }
}
