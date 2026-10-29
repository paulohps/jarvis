<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\SetWhatsappChatNotifications;
use App\Actions\Whatsapp\SetWhatsappChatPrivacy;
use App\Actions\Whatsapp\SetWhatsappPriorityColors;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappAccountResource;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The settings of the WhatsApp chats, as in the web chat settings: the
 * colors of the priorities (one per user, out of a palette) and, per
 * number, the defaults of its chats (read receipts, "typing…", pushes).
 */
class WhatsappChatSettingsController extends Controller
{
    /**
     * `GET /api/v1/whatsapp/priority-colors`: the palette (`{name: hex}`)
     * and the color of each priority (`{1: name, …}`).
     */
    public function priorityColors(Request $request): JsonResponse
    {
        return $this->colorsResponse($this->actor($request));
    }

    /**
     * `PUT /api/v1/whatsapp/priority-colors`: `{ colors: {1: name, …} }`;
     * a priority left out or null takes its default.
     *
     * @throws ValidationException
     */
    public function savePriorityColors(Request $request, SetWhatsappPriorityColors $setColors): JsonResponse
    {
        $user = $this->actor($request);
        $setColors($user, (array) $request->input('colors', []));

        return $this->colorsResponse($user->refresh());
    }

    /**
     * `PATCH /api/v1/whatsapp/chat-settings/{account}`: `{ sends_read_receipts?,
     * sends_typing?, notifies_messages?, syncs_phone_reads? }`, the defaults of the number's
     * chats; the ones left out keep their value.
     *
     * @throws ValidationException
     */
    public function updateAccount(Request $request, WhatsappAccount $account, SetWhatsappChatPrivacy $setPrivacy, SetWhatsappChatNotifications $setNotifications): WhatsappAccountResource
    {
        $input = $request->validate([
            'sends_read_receipts' => ['sometimes', 'boolean'],
            'sends_typing' => ['sometimes', 'boolean'],
            'notifies_messages' => ['sometimes', 'boolean'],
            'syncs_phone_reads' => ['sometimes', 'boolean'],
        ]);
        $user = $this->actor($request);

        $setPrivacy->forAccount($user, $account, (bool) ($input['sends_read_receipts'] ?? $account->sends_read_receipts), (bool) ($input['sends_typing'] ?? $account->sends_typing));

        if (array_key_exists('notifies_messages', $input)) {
            $setNotifications->forAccount($user, $account, (bool) $input['notifies_messages']);
        }

        if (array_key_exists('syncs_phone_reads', $input)) {
            $setNotifications->syncPhoneReads($user, $account, (bool) $input['syncs_phone_reads']);
        }

        return new WhatsappAccountResource($account->refresh()->load('status'));
    }

    private function colorsResponse(User $user): JsonResponse
    {
        return response()->json([
            'palette' => WhatsappTarget::PRIORITY_COLORS,
            'colors' => $user->whatsappPriorityColors(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
