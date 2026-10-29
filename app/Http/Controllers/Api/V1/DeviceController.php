<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Devices\RegisterDevice;
use App\Actions\Devices\RevokeDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The user's devices (US-3.4): register the one signed in with this token,
 * list them and revoke one. Only ever the user's own.
 */
class DeviceController extends Controller
{
    /**
     * `GET /api/v1/devices`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return DeviceResource::collection($user->devices()->with(['platform', 'personalAccessToken'])->latestUsedFirst()->get());
    }

    /**
     * `POST /api/v1/devices`: 201 the first time, 200 on the next ones.
     */
    public function store(Request $request, RegisterDevice $registerDevice): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $device = $registerDevice($user, $user->currentAccessToken(), $request->only(['platform', 'name', 'push_token']));

        return (new DeviceResource($device->load('platform')))
            ->response()
            ->setStatusCode($device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * `DELETE /api/v1/devices/{device}`: revokes the device and its token.
     */
    public function destroy(Request $request, Device $device, RevokeDevice $revokeDevice): Response
    {
        /** @var User $user */
        $user = $request->user();

        $revokeDevice($user, $device);

        return response()->noContent();
    }
}
