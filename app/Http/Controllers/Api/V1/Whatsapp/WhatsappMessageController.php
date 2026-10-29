<?php

namespace App\Http\Controllers\Api\V1\Whatsapp;

use App\Actions\Whatsapp\CancelWhatsappMessage;
use App\Actions\Whatsapp\DeleteWhatsappMessage;
use App\Actions\Whatsapp\PauseWhatsappMessage;
use App\Actions\Whatsapp\ResumeWhatsappMessage;
use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\UpdateWhatsappMessage;
use App\Http\Controllers\Controller;
use App\Http\Resources\WhatsappMessageResource;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The user's scheduled WhatsApp messages in the apps (US-7.3), through the
 * same Actions as the web form. The input: `account`, `targets`, `numbers`,
 * `body` (see WhatsappMessageFields), `ai_prompt` and `poll` (SavesWhatsappMessage) and `recurrence` (the input of
 * SaveRecurrence). Only the author reaches a message; anyone else gets a
 * 403.
 */
class WhatsappMessageController extends Controller
{
    /**
     * `GET /api/v1/whatsapp/messages`: the user's own, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $messages = WhatsappMessage::query()
            ->where('user_id', $this->actor($request)->id)
            ->with(['account', 'targets.type', 'recurrence.frequency', 'recurrence.weekdays'])
            ->latest('id')
            ->get();

        return WhatsappMessageResource::collection($messages);
    }

    /**
     * `POST /api/v1/whatsapp/messages`.
     */
    public function store(Request $request, ScheduleWhatsappMessage $scheduleMessage): JsonResponse
    {
        $message = $scheduleMessage($this->actor($request), $this->input($request));

        return $this->messageResource($message)->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/whatsapp/messages/{message}`.
     */
    public function show(WhatsappMessage $message): WhatsappMessageResource
    {
        Gate::authorize('view', $message);

        return $this->messageResource($message);
    }

    /**
     * `PATCH /api/v1/whatsapp/messages/{message}`: the whole message, as on
     * the web form.
     */
    public function update(Request $request, WhatsappMessage $message, UpdateWhatsappMessage $updateMessage): WhatsappMessageResource
    {
        return $this->messageResource($updateMessage($this->actor($request), $message, $this->input($request)));
    }

    /**
     * `DELETE /api/v1/whatsapp/messages/{message}`.
     */
    public function destroy(Request $request, WhatsappMessage $message, DeleteWhatsappMessage $deleteMessage): Response
    {
        $deleteMessage($this->actor($request), $message);

        return response()->noContent();
    }

    /**
     * `POST /api/v1/whatsapp/messages/{message}/cancel`: a `once` message,
     * before its time.
     */
    public function cancel(Request $request, WhatsappMessage $message, CancelWhatsappMessage $cancelMessage): WhatsappMessageResource
    {
        return $this->messageResource($cancelMessage($this->actor($request), $message));
    }

    /**
     * `POST /api/v1/whatsapp/messages/{message}/pause`.
     */
    public function pause(Request $request, WhatsappMessage $message, PauseWhatsappMessage $pauseMessage): WhatsappMessageResource
    {
        return $this->messageResource($pauseMessage($this->actor($request), $message));
    }

    /**
     * `POST /api/v1/whatsapp/messages/{message}/resume`.
     */
    public function resume(Request $request, WhatsappMessage $message, ResumeWhatsappMessage $resumeMessage): WhatsappMessageResource
    {
        return $this->messageResource($resumeMessage($this->actor($request), $message));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->only(['account', 'targets', 'numbers', 'body', 'ai_prompt', 'poll', 'recurrence']);
    }

    private function messageResource(WhatsappMessage $message): WhatsappMessageResource
    {
        return new WhatsappMessageResource($message->load(['account', 'targets.type', 'recurrence.frequency', 'recurrence.weekdays']));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
