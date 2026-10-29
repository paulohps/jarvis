<?php

namespace App\Actions\Whatsapp;

use App\Jobs\DeliverWhatsappMessage;
use App\Models\DeliveryStatus;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\WhatsappDeliveryFailure;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The WhatsApp service (US-7.4; Key Concept "WhatsApp service"): the only
 * way anything sends a WhatsApp message — scheduled messages, "Send now",
 * the notification channel and voice commands. Nothing else calls
 * `EvolutionApiClient::sendText()`.
 *
 * It logs one `whatsapp_deliveries` row (`pending`) for the target, with
 * the body as it goes out (already rendered, plain text), and queues
 * `DeliverWhatsappMessage` on the `whatsapp` queue, which sends it with 3
 * attempts.
 *
 * - An account that is not `open` fails right away, without calling the
 *   Evolution API: the delivery is `failed` with the reason, and the owner
 *   gets `whatsapp_message_failed`.
 * - A scheduled run is idempotent by message, target and `scheduled_for`:
 *   the slot already logged is returned as it is, and nothing is sent again.
 */
final readonly class SendWhatsAppMessage
{
    public function __construct(private WhatsappDeliveryFailure $failure) {}

    /**
     * @param  string  $source  a `WhatsappDeliverySource` slug
     * @param  array{path: string, mime: string, name: string|null}|null  $media  a file on the private disk (WhatsappChatMedia), sent with `$body` as its caption
     * @param  array<string, mixed>|null  $payload  the message it answers, who it mentions, or a poll, location, contacts or sticker (WhatsappDelivery::$payload)
     * @param  bool  $now  sends before returning instead of queueing (the chat layer, which hands the message back as recorded); a failure throws after the delivery is marked failed
     *
     * @throws InvalidArgumentException when the target is not the account's
     */
    public function __invoke(
        WhatsappAccount $account,
        WhatsappTarget $target,
        string $body,
        string $source,
        ?WhatsappMessage $message = null,
        ?CarbonInterface $scheduledFor = null,
        ?User $triggeredBy = null,
        ?array $media = null,
        ?array $payload = null,
        bool $now = false,
    ): WhatsappDelivery {
        if ($target->whatsapp_account_id !== $account->id) {
            throw new InvalidArgumentException("Target {$target->friendly_id} does not belong to account {$account->friendly_id}.");
        }

        $existing = $this->existingSlot($target, $message, $scheduledFor);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $delivery = WhatsappDelivery::query()->create([
                'whatsapp_account_id' => $account->id,
                'whatsapp_target_id' => $target->id,
                'whatsapp_message_id' => $message?->id,
                'whatsapp_delivery_source_id' => WhatsappDeliverySource::idFor($source),
                'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::PENDING),
                'body' => $body,
                'media_path' => $media['path'] ?? null,
                'media_mime' => $media['mime'] ?? null,
                'media_name' => $media['name'] ?? null,
                'payload' => $payload !== [] ? $payload : null,
                'scheduled_for' => $scheduledFor,
                'attempts' => 0,
                'triggered_by_id' => $triggeredBy?->id,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Another worker logged the same slot first.
            return $this->existingSlot($target, $message, $scheduledFor) ?? throw $exception;
        }

        $delivery->setRelation('account', $account)->setRelation('target', $target);

        if (! $account->isOpen()) {
            $this->failure->record($delivery, WhatsappDeliveryFailure::accountNotOpenMessage($account));

            return $delivery;
        }

        if ($now) {
            DeliverWhatsappMessage::dispatchSync($delivery);

            return $delivery->refresh();
        }

        DeliverWhatsappMessage::dispatch($delivery)->afterCommit();

        return $delivery;
    }

    /**
     * The delivery already logged for a scheduled slot, if any.
     */
    private function existingSlot(WhatsappTarget $target, ?WhatsappMessage $message, ?CarbonInterface $scheduledFor): ?WhatsappDelivery
    {
        if ($message === null || $scheduledFor === null) {
            return null;
        }

        return WhatsappDelivery::query()
            ->where('whatsapp_message_id', $message->id)
            ->where('whatsapp_target_id', $target->id)
            ->where('scheduled_for', $scheduledFor)
            ->first();
    }
}
