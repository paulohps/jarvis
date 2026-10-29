<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\AuthenticateApiDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/login` (US-1.5): e-mail, password and `device_name`
 * give back `{ token, user }`; a refusal is a 422.
 */
class LoginController extends Controller
{
    public function __invoke(Request $request, AuthenticateApiDevice $authenticateApiDevice): JsonResponse
    {
        $token = $authenticateApiDevice($request->only(['email', 'password', 'device_name']), (string) $request->ip());

        /** @var User $user */
        $user = $token->accessToken->tokenable;

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }
}
