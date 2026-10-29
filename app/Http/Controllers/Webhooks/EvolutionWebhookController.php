<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Whatsapp\HandleEvolutionWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/webhooks/evolution` (Workflow 9): the QR codes and the
 * connection changes of the instances, behind the shared secret. Always a
 * 200 once authenticated, telling whether the event was handled or ignored
 * (unknown instance or event), so the Evolution API never retries it.
 */
class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, HandleEvolutionWebhook $handleWebhook): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return response()->json(['status' => $handleWebhook($payload) ? 'handled' : 'ignored']);
    }
}
