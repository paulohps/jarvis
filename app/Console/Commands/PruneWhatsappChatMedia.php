<?php

namespace App\Console\Commands;

use App\Models\DeliveryStatus;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappChatMedia;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Frees storage, daily: the file of a chat message nobody opened for
 * `services.evolution.media_keep_days` (`media_accessed_at`) is removed
 * from the private disk and the message goes back to "Load", which fetches
 * it again from the Evolution API while WhatsApp still has it.
 *
 * A file a delivery still has to send (pending or failed, so it may be
 * retried), or a scheduled message sends again, is kept.
 */
#[Signature('whatsapp:prune-media {--days= : Remove files not opened for this many days (default: services.evolution.media_keep_days)}')]
#[Description('Remove the WhatsApp chat files nobody opened for a while')]
class PruneWhatsappChatMedia extends Command
{
    public const int CHUNK = 200;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.evolution.media_keep_days'));

        if ($days < 1) {
            $this->error('The days must be at least 1.');

            return self::FAILURE;
        }

        $disk = WhatsappChatMedia::disk();
        $pendingPaths = WhatsappDelivery::query()
            ->whereNotNull('media_path')
            ->where('delivery_status_id', '!=', DeliveryStatus::idFor(DeliveryStatus::SENT))
            ->select('media_path');
        $removed = 0;

        WhatsappChatMessage::query()
            ->whereNotNull('media_path')
            ->where(fn (Builder $query) => $query->whereNull('media_accessed_at')->orWhere('media_accessed_at', '<', now()->subDays($days)))
            ->whereNotIn('media_path', $pendingPaths)
            ->whereNotIn('media_path', WhatsappMessage::query()->whereNotNull('media_path')->select('media_path'))
            ->chunkById(self::CHUNK, function ($messages) use ($disk, &$removed): void {
                foreach ($messages as $message) {
                    $disk->delete((string) $message->media_path);
                    $message->forceFill(['media_path' => null, 'media_accessed_at' => null])->save();
                    $removed++;
                }
            });

        $this->info("Removed {$removed} WhatsApp chat files not opened for {$days} days.");

        return self::SUCCESS;
    }
}
