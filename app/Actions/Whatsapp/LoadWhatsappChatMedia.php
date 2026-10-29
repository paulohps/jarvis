<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Services\Whatsapp\EvolutionApiClient;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Brings the file of a media message into Jarvis, so the chat shows it
 * (an image, a video or an audio plays in place) and it can be downloaded.
 * Files are fetched only when asked for: the first time takes it from the
 * Evolution API (`getBase64FromMediaMessage`) and keeps it on the private
 * disk (WhatsappChatMedia: R2 in production, local in development); a
 * file already kept is not fetched again.
 *
 * An audio comes converted to MP4/AAC (AUDIO_MIME, by the Evolution API),
 * since Safari does not play WhatsApp's Ogg/Opus; one kept before that, in
 * Ogg, is fetched again converted, or stays as it is when WhatsApp no
 * longer hands it over.
 *
 * Returns false for a message without a file, or one above
 * WhatsappChatMessage::MAX_MEDIA_BYTES, which stays on the phone.
 *
 * Only the owner of the account.
 */
final readonly class LoadWhatsappChatMedia
{
    public const string AUDIO_MIME = 'audio/mp4';

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException when WhatsApp does not hand the file over
     */
    public function __invoke(User $actor, WhatsappChatMessage $message): bool
    {
        $account = $message->target->account;

        Gate::forUser($actor)->authorize('view', $account);

        $isAudio = $message->mediaKind() === 'audio';
        $kept = $message->media_path !== null && WhatsappChatMedia::disk()->exists($message->media_path);

        if ($kept && (! $isAudio || $this->playsEverywhere($message))) {
            return true;
        }

        if (! $kept && ! $message->canDownloadMedia()) {
            return false;
        }

        try {
            $media = $this->client->mediaOf($account->instance_name, $message->external_id, convertAudio: $isAudio);
        } catch (EvolutionApiException $exception) {
            if (! $kept) {
                throw $exception;
            }

            report($exception);

            return true;
        }

        if (strlen($media['content']) > WhatsappChatMessage::MAX_MEDIA_BYTES) {
            return $kept;
        }

        $previousPath = $kept ? $message->media_path : null;

        if ($isAudio && $media['mime'] === self::AUDIO_MIME) {
            // The size WhatsApp told is the Ogg's: the MP4 keeps its own.
            $message->media_mime = self::AUDIO_MIME;
            $message->media_size = null;
        }

        WhatsappChatMedia::store($message, $media['content'], $media['mime'], $media['name']);

        if ($previousPath !== null && $previousPath !== $message->media_path) {
            WhatsappChatMedia::disk()->delete($previousPath);
        }

        return true;
    }

    /**
     * Whether a kept audio is in a format every browser plays: the
     * converted one, or one the user sent from the screen (MP3, M4A…), not
     * WhatsApp's Ogg.
     */
    private function playsEverywhere(WhatsappChatMessage $message): bool
    {
        return ! str_starts_with((string) $message->media_mime, 'audio/ogg');
    }
}
