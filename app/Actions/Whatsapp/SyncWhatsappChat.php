<?php

namespace App\Actions\Whatsapp;

use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\EvolutionApiClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * "Sync this chat" on the WhatsApp screen: brings one chat in line with
 * what the Evolution API has, without waiting for a whole-account sync.
 *
 * - The name: a contact's push name, a group's subject (kept when WhatsApp
 *   gives none), and the picture (RefreshWhatsappChatPicture's, asked now).
 * - The latest messages, up to MAX_PAGES of PER_PAGE: by the chat's JID
 *   and, for a contact, also those kept under its `@lid` (`remoteJidAlt`),
 *   through RecordWhatsappChatMessages, which adds the missing ones and
 *   brings the ticks of the kept ones up to date.
 * - The instance's webhook is pointed at the current events first, so a
 *   number linked before the receipts were reported starts reporting them;
 *   a failure there does not stop the sync.
 *
 * Returns how many messages were new, or null while the number is not
 * connected.
 *
 * Only the owner of the account, with the `whatsapp` module.
 */
final readonly class SyncWhatsappChat
{
    use AuthorizesWhatsappModule;

    public const int PER_PAGE = 100;

    public const int MAX_PAGES = 3;

    public function __construct(
        private EvolutionApiClient $client,
        private RecordWhatsappChatMessages $recordMessages,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws EvolutionApiException when WhatsApp cannot be reached
     */
    public function __invoke(User $actor, WhatsappTarget $chat): ?int
    {
        $this->authorizeWhatsapp($actor);
        Gate::forUser($actor)->authorize('use', $chat->account);

        $account = $chat->account;

        if (! $account->isOpen()) {
            return null;
        }

        $instance = $account->instance_name;

        try {
            $this->client->setWebhook($instance);
        } catch (EvolutionApiException $exception) {
            report($exception);
        }

        if ($chat->isGroup()) {
            ['name' => $name, 'picture' => $picture] = $this->client->groupInfoOf($instance, $chat->jid);
        } else {
            $name = $this->client->contactNameOf($instance, $chat->jid);
            $picture = $this->client->profilePictureOf($instance, $chat->jid);
        }

        $chat->forceFill([
            'name' => $name ?? $chat->name,
            'picture_url' => $picture,
            'picture_checked_at' => now(),
        ])->save();

        $filters = [['remoteJid' => $chat->jid]];

        if (str_ends_with($chat->jid, WhatsappTarget::CONTACT_JID_SUFFIX)) {
            $filters[] = ['remoteJidAlt' => $chat->jid];
        }

        $recorded = 0;

        foreach ($filters as $where) {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $result = $this->client->findMessages($instance, $page, self::PER_PAGE, $where);
                $recorded += ($this->recordMessages)($account, $result['records']);

                if ($page >= $result['pages']) {
                    break;
                }
            }
        }

        return $recorded;
    }
}
