<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\SendWhatsappNow;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappDeliveryResource;
use App\Models\User;
use App\Models\WhatsappDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Send now" and the history of WhatsApp deliveries in the apps (US-7.5),
 * the same as the web: only from the user's own accounts.
 */
class WhatsappDeliveryController extends Controller
{
    public const int PER_PAGE = 20;

    public const int MAX_PER_PAGE = 50;

    /**
     * `GET /api/v1/whatsapp/deliveries`: newest first, paginated (`page`,
     * `per_page` up to 50); scheduled, manual, notification and voice
     * deliveries alike.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', self::PER_PAGE), 1), self::MAX_PER_PAGE);

        return WhatsappDeliveryResource::collection(WhatsappDelivery::query()
            ->whereIn('whatsapp_account_id', $this->actor($request)->whatsappAccounts()->select('id'))
            ->with(['account', 'target.type', 'message', 'status', 'source'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage));
    }

    /**
     * `POST /api/v1/whatsapp/send-now`: `account`, `targets`, `numbers` and
     * `body`, sent right away without scheduling anything. A 202 with one
     * `manual` delivery per target; the sending goes on in the queue.
     */
    public function sendNow(Request $request, SendWhatsappNow $sendNow): JsonResponse
    {
        $deliveries = $sendNow($this->actor($request), $request->only(['account', 'targets', 'numbers', 'body', 'poll']));

        return WhatsappDeliveryResource::collection($deliveries->load(['account', 'target.type', 'message', 'status', 'source']))
            ->response()
            ->setStatusCode(202);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
