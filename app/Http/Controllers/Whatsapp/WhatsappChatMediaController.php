<?php

namespace App\Http\Controllers\Whatsapp;

use App\Actions\Whatsapp\LoadWhatsappChatMedia;
use App\Exceptions\EvolutionApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The file of a media of the WhatsApp screen: shown in the chat (inline),
 * or downloaded under the name WhatsApp gave it with `?download=1`. A file
 * not loaded yet is loaded first (LoadWhatsappChatMedia). A file above
 * WhatsappChatMessage::MAX_MEDIA_BYTES, or a message without one, is a
 * 404; the Evolution API failing is a 502.
 *
 * Only the owner of the account: signed in on the web, or, on the apps'
 * route, holding the temporary signed URL Jarvis gave them (the media of
 * their own chats only), which stands for the owner.
 */
class WhatsappChatMediaController extends Controller
{
    public function __invoke(Request $request, WhatsappChatMessage $message, LoadWhatsappChatMedia $loadMedia): Response
    {
        /** @var User $user */
        $user = $request->user() ?? $message->target->account->user;

        try {
            abort_unless($loadMedia($user, $message), 404);
        } catch (EvolutionApiException $exception) {
            report($exception);

            abort(502, __('WhatsApp could not hand over the file. Try again in a moment.'));
        }

        WhatsappChatMedia::touch($message);

        $disk = WhatsappChatMedia::disk();
        $path = (string) $message->media_path;
        $name = $message->media_name ?? basename($path);
        $headers = ['Content-Type' => $message->media_mime ?? 'application/octet-stream'];

        return $request->boolean('download')
            ? $disk->download($path, $name, $headers)
            : $disk->response($path, $name, $headers + ['Cache-Control' => 'private, max-age=86400']);
    }
}
