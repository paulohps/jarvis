<?php

namespace App\Http\Controllers\Api\V1\Chats;

use App\Actions\Chats\CancelChatScheduledMessage;
use App\Http\Controllers\Controller;
use App\Models\ChatScheduledMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `DELETE /api/v1/chats/scheduled/{scheduled}`: cancels a scheduled
 * message not sent yet.
 */
class ChatScheduledMessageController extends Controller
{
    public function destroy(Request $request, string $scheduled, CancelChatScheduledMessage $cancel): Response
    {
        /** @var User $user */
        $user = $request->user();

        $cancel($user, ChatScheduledMessage::query()->where('user_id', $user->id)->byFriendlyId($scheduled)->firstOrFail());

        return response()->noContent();
    }
}
