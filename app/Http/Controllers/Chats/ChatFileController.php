<?php

namespace App\Http\Controllers\Chats;

use App\Http\Controllers\Controller;
use App\Models\ChatConversation;
use App\Models\User;
use App\Services\Chats\ChatProviderException;
use App\Services\Chats\ChatProviderRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file of a message of the chat screen, fetched from its provider by
 * the driver: shown inline, or downloaded under its own name with
 * `?download=1`. A message without that file is a 404; the provider
 * refusing is a 502.
 *
 * Only the owner of the conversation: signed in on the web, or holding
 * the temporary signed URL the driver put in the message (the apps),
 * which stands for the owner.
 */
class ChatFileController extends Controller
{
    public function __invoke(Request $request, ChatConversation $conversation, string $message, string $file, ChatProviderRegistry $registry): Response
    {
        /** @var User $user */
        $user = $request->user() ?? $conversation->user;

        abort_unless($conversation->user_id === $user->id, 403);
        abort_unless($registry->has($conversation->provider->slug), 404);

        try {
            $contents = $registry->for($conversation)->file($conversation, $message, $file);
        } catch (ChatProviderException $exception) {
            abort(502, $exception->getMessage());
        }

        abort_if($contents === null, 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($contents->contents, 200, [
            'Content-Type' => $contents->mime,
            'Content-Length' => (string) strlen($contents->contents),
            'Content-Disposition' => $disposition.'; filename="'.addcslashes($contents->name, '"\\').'"',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
