<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Http\Controllers\Controller;
use App\Models\ChatProvider;
use App\Models\User;
use App\Services\Chats\ChatAccountData;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/chats/providers`: the channels the user may use on the
 * chat screen, each with what it supports in general and the user's
 * accounts on it.
 */
class ChatProviderController extends Controller
{
    public function index(Request $request, ChatProviderRegistry $registry): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $registry->providersFor($user)->map(function (ChatProvider $provider) use ($registry, $user): array {
            $driver = $registry->driver($provider->slug);

            return [
                'slug' => $provider->slug,
                'name' => $provider->name,
                'capabilities' => $driver->capabilities()->toArray(),
                'accounts' => $driver->accountsOf($user)->map(fn (ChatAccountData $account): array => $account->toArray())->values()->all(),
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }
}
