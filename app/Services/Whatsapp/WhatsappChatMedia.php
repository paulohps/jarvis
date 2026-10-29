<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappChatMessage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Where the files of the WhatsApp chats live: the private disk
 * (`filesystems.private`, as the invoice PDFs), under DIRECTORY, one
 * folder per account. Served only through WhatsappChatMediaController;
 * a file nobody opens for a while is removed (PruneWhatsappChatMedia) and
 * fetched again when asked for.
 */
final class WhatsappChatMedia
{
    public const string DIRECTORY = 'whatsapp-media';

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.private'));
    }

    /**
     * Keeps the file of a received or sent message and records where it is,
     * with its type when WhatsApp did not tell it.
     */
    public static function store(WhatsappChatMessage $message, string $content, ?string $mime = null, ?string $name = null): void
    {
        $mime = $message->media_mime ?? $mime ?? 'application/octet-stream';
        $path = self::pathFor($message->target->account->friendly_id, $message->friendly_id, $mime);

        self::disk()->put($path, $content);

        $message->forceFill([
            'media_path' => $path,
            'media_mime' => $mime,
            'media_name' => $message->media_name ?? $name,
            'media_size' => $message->media_size ?? strlen($content),
            'media_accessed_at' => now(),
        ])->save();
    }

    /**
     * Records that the file of a message was opened, at most once an hour,
     * so whatsapp:prune-media keeps what is still in use.
     */
    public static function touch(WhatsappChatMessage $message): void
    {
        if ($message->media_accessed_at === null || $message->media_accessed_at->lt(now()->subHour())) {
            $message->forceFill(['media_accessed_at' => now()])->save();
        }
    }

    /**
     * Keeps a file being sent from the WhatsApp screen, before it is a
     * message, and returns its path.
     */
    public static function storeOutgoing(string $accountId, string $content, string $mime): string
    {
        $path = self::pathFor($accountId, 'out_'.Str::random(24), $mime);

        self::disk()->put($path, $content);

        return $path;
    }

    private static function pathFor(string $accountId, string $fileId, string $mime): string
    {
        $extension = MimeTypes::getDefault()->getExtensions(strtok($mime, ';') ?: $mime)[0] ?? 'bin';

        return self::DIRECTORY."/{$accountId}/{$fileId}.{$extension}";
    }
}
