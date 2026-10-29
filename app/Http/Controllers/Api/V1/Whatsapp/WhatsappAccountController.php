<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappAccountResource;
use App\Http\Resources\WhatsappTargetResource;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The user's own WhatsApp numbers in the apps (US-7.1, US-7.2), read only:
 * connecting one by QR code, reconnecting and removing stay on the web.
 * Nobody else's accounts, the super admin's included.
 */
class WhatsappAccountController extends Controller
{
    public const int TARGETS_PER_PAGE = 30;

    public const int MAX_TARGETS_PER_PAGE = 100;

    /**
     * `GET /api/v1/whatsapp/accounts`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return WhatsappAccountResource::collection(
            $user->whatsappAccounts()->with('status')->orderBy('nickname')->orderBy('id')->get(),
        );
    }

    /**
     * `GET /api/v1/whatsapp/accounts/{account}/targets?search=`: the
     * account's contacts and groups matching the search by name or number,
     * contacts first and then by name, paginated (`per_page` up to 100).
     */
    public function targets(Request $request, WhatsappAccount $account): AnonymousResourceCollection
    {
        Gate::authorize('view', $account);

        $search = trim($request->string('search')->toString());
        $perPage = min(max($request->integer('per_page', self::TARGETS_PER_PAGE), 1), self::MAX_TARGETS_PER_PAGE);

        return WhatsappTargetResource::collection($account->targets()
            ->with('type')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('jid', '%'.ltrim($search, '+').'%')))
            ->orderBy('whatsapp_target_type_id')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage));
    }
}
