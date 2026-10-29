<?php

namespace App\Actions\Auth;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Signs the app out (US-1.5): only the token of this call is deleted, so the
 * user's other devices stay signed in. The device registered with it goes
 * too, so a signed-out app gets no more push.
 */
final readonly class RevokeCurrentToken
{
    public function __invoke(PersonalAccessToken $token): void
    {
        DB::transaction(function () use ($token): void {
            Device::query()->where('personal_access_token_id', $token->getKey())->delete();

            $token->delete();
        });
    }
}
