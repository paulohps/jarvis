<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Voice\ConfirmVoiceAction;
use App\Actions\Voice\ExecuteVoiceAction;
use App\Ai\Agents\VoiceCommandAgent;
use App\Exceptions\AiProviderNotConfigured;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The voice commands of the apps (US-10.2, US-10.3; Workflow 13). Every
 * answer is a VoiceResult: `action`, `status` and a translated `message`
 * for the app to show and speak. 20 requests per minute per user.
 */
class VoiceCommandController extends Controller
{
    public const int MAX_TEXT = 1000;

    /**
     * `POST /api/v1/voice/commands`: `{ text }`, interpreted by the remote
     * agent (VoiceCommandAgent) and run by ExecuteVoiceAction. A text no
     * tool fits is `failed` with the agent's reply. No active AI provider
     * connection is a 503; a provider failing is a 502.
     *
     * @throws AiProviderNotConfigured
     * @throws ValidationException
     */
    public function interpret(Request $request, ExecuteVoiceAction $execute): JsonResponse
    {
        /** @var array{text: string} $validated */
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:'.self::MAX_TEXT],
        ]);

        $actor = $this->actor($request);
        $agent = VoiceCommandAgent::forUser($actor);

        try {
            $interpretation = $agent->interpret($validated['text']);
        } catch (Throwable $exception) {
            report($exception);

            throw new HttpException(502, __('The voice assistant is unavailable right now. Try again in a moment.'), $exception);
        }

        $result = $interpretation->action === null
            ? VoiceResult::failed(null, filled($interpretation->reply) ? $interpretation->reply : __('Sorry, I did not understand that.'))
            : $execute($actor, $interpretation->action);

        return response()->json($result->toArray());
    }

    /**
     * `POST /api/v1/voice/actions`: `{ action, parameters }`, an action
     * already structured by the app's on-device interpreter, or sent back
     * with the option picked after a clarification. Run by the same
     * ExecuteVoiceAction as the remote commands; a 422 when malformed.
     *
     * @throws ValidationException
     */
    public function execute(Request $request, ExecuteVoiceAction $execute): JsonResponse
    {
        $action = VoiceAction::from($request->input('action'), $request->input('parameters', []));

        return response()->json($execute($this->actor($request), $action)->toArray());
    }

    /**
     * `POST /api/v1/voice/commands/confirm`: `confirmation_token`, from a
     * `needs_confirmation` answer. Sends the WhatsApp message (`voice`
     * delivery); a token expired, used or unknown is a 422.
     */
    public function confirm(Request $request, ConfirmVoiceAction $confirm): JsonResponse
    {
        return response()->json($confirm($this->actor($request), $request->input('confirmation_token'))->toArray());
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
