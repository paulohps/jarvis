<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The invitations in the super admin's area of the apps (US-1.1), newest
 * first, through the same Actions as the web admin.
 */
class InvitationController extends Controller
{
    /**
     * `GET /api/v1/admin/invitations`.
     */
    public function index(): AnonymousResourceCollection
    {
        return InvitationResource::collection(
            Invitation::query()->with(['workspace', 'modules', 'locale'])->latest()->latest('id')->get(),
        );
    }

    /**
     * `POST /api/v1/admin/invitations`: `email`, `workspace`, `modules`, `locale`.
     */
    public function store(Request $request, InviteUser $inviteUser): JsonResponse
    {
        $invitation = $inviteUser($this->actor($request), $request->only(['email', 'workspace', 'modules', 'locale']));

        return (new InvitationResource($invitation->load(['workspace', 'modules', 'locale'])))->response()->setStatusCode(201);
    }

    /**
     * `POST /api/v1/admin/invitations/{invitation}/resend`.
     */
    public function resend(Request $request, Invitation $invitation, ResendInvitation $resendInvitation): InvitationResource
    {
        return new InvitationResource($resendInvitation($this->actor($request), $invitation)->load(['workspace', 'modules', 'locale']));
    }

    /**
     * `POST /api/v1/admin/invitations/{invitation}/revoke`.
     */
    public function revoke(Request $request, Invitation $invitation, RevokeInvitation $revokeInvitation): InvitationResource
    {
        return new InvitationResource($revokeInvitation($this->actor($request), $invitation)->load(['workspace', 'modules', 'locale']));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
