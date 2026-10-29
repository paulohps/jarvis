<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The pictures of who sent the messages of a group, shown beside them as on
 * WhatsApp Web. A sender who is one of the account's contacts takes the
 * contact's picture; the others are asked of WhatsApp, at most BATCH per
 * call, and kept in the cache for a day (an empty string when they have
 * none or WhatsApp could not be reached, so they are not asked again).
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class LoadWhatsappSenderPictures
{
    use AuthorizesWhatsappModule;

    public const int BATCH = 20;

    public const int KEEP_HOURS = 24;

    public function __construct(private EvolutionApiClient $client) {}

    /**
     * The picture of each sender: its URL, an empty string for none, or
     * null while not asked yet.
     *
     * @param  list<string>  $senderJids
     * @return array<string, string|null>
     */
    public static function picturesOf(WhatsappTarget $group, array $senderJids): array
    {
        $senderJids = array_values(array_unique($senderJids));

        if ($senderJids === []) {
            return [];
        }

        $fromContacts = WhatsappTarget::query()
            ->where('whatsapp_account_id', $group->whatsapp_account_id)
            ->whereIn('jid', $senderJids)
            ->whereNotNull('picture_url')
            ->pluck('picture_url', 'jid')
            ->all();

        $cached = Cache::many(array_map(fn (string $jid): string => self::cacheKey($group, $jid), $senderJids));
        $pictures = [];

        foreach ($senderJids as $jid) {
            $pictures[$jid] = $fromContacts[$jid] ?? $cached[self::cacheKey($group, $jid)] ?? null;
        }

        return $pictures;
    }

    /**
     * Asks WhatsApp for the pictures of the senders not asked yet; returns
     * how many were asked.
     *
     * @param  list<string>  $senderJids
     *
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, WhatsappTarget $group, array $senderJids): int
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $group->account);

        if (! $group->isGroup() || ! $group->account->isOpen()) {
            return 0;
        }

        $missing = array_slice(array_keys(array_filter(self::picturesOf($group, $senderJids), fn (?string $picture): bool => $picture === null)), 0, self::BATCH);

        foreach ($missing as $jid) {
            try {
                $picture = $this->client->profilePictureOf($group->account->instance_name, $jid);
            } catch (Throwable $exception) {
                report($exception);
                $picture = null;
            }

            Cache::put(self::cacheKey($group, $jid), $picture ?? '', now()->addHours(self::KEEP_HOURS));
        }

        return count($missing);
    }

    private static function cacheKey(WhatsappTarget $group, string $jid): string
    {
        return "whatsapp-sender-picture:{$group->whatsapp_account_id}:{$jid}";
    }
}
