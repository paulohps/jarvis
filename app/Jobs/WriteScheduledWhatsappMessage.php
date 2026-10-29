<?php

namespace App\Jobs;

use App\Actions\Whatsapp\SendWhatsAppMessage;
use App\Ai\Agents\WhatsappReplyAgent;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Models\DeliveryStatus;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Whatsapp\WhatsappDeliveryFailure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use RuntimeException;
use Throwable;

/**
 * One run of a scheduled message the AI writes (WhatsappMessage::$ai_prompt),
 * for one target: the AI writes it from the prompt, reading the chat
 * (WhatsappReplyAgent::writeScheduled()), and SendWhatsAppMessage sends it
 * as any scheduled run, idempotent by message, target and slot. Queued by
 * WhatsappMessage::runScheduledSlot() on the `whatsapp` queue.
 *
 * A slot already logged asks the AI nothing. 2 attempts, a minute apart, and
 * more time than the AI's own timeout; when the last one fails, the slot is
 * logged as a `failed` delivery (the prompt as its body, so the owner knows
 * which) and the owner is told (WhatsappDeliveryFailure).
 */
#[Tries(2)]
#[Backoff(60)]
#[Timeout(90)]
#[DeleteWhenMissingModels]
class WriteScheduledWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WhatsappMessage $message,
        public WhatsappTarget $target,
        public CarbonImmutable $slot,
    ) {
        $this->onQueue(DeliverWhatsappMessage::QUEUE);
    }

    public function handle(SendWhatsAppMessage $send): void
    {
        $message = $this->message;

        if ($message->trashed() || $message->isCancelled() || ! $message->writesWithAi() || $this->slotLogged()) {
            return;
        }

        $message->loadMissing(['account.status', 'user']);

        $text = WhatsappReplyAgent::forUser($message->user)->writeScheduled($this->target, (string) $message->ai_prompt);

        if ($text === '') {
            throw new RuntimeException("The AI wrote nothing for the scheduled message {$message->friendly_id}.");
        }

        $send($message->account, $this->target, $text, WhatsappDeliverySource::SCHEDULED, $message, $this->slot);
    }

    /**
     * Logs the slot as failed, with why, and tells the owner.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->slotLogged()) {
            return;
        }

        try {
            $delivery = WhatsappDelivery::query()->create([
                'whatsapp_account_id' => $this->message->whatsapp_account_id,
                'whatsapp_target_id' => $this->target->id,
                'whatsapp_message_id' => $this->message->id,
                'whatsapp_delivery_source_id' => WhatsappDeliverySource::idFor(WhatsappDeliverySource::SCHEDULED),
                'delivery_status_id' => DeliveryStatus::idFor(DeliveryStatus::PENDING),
                'body' => (string) $this->message->ai_prompt,
                'scheduled_for' => $this->slot,
                'attempts' => $this->attempts(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        app(WhatsappDeliveryFailure::class)->record($delivery, match (true) {
            $exception instanceof AiProviderNotConfigured => __('No AI is set up for WhatsApp replies. Ask the administrator to set one.'),
            $exception instanceof AiAnswerCutShort => __('The AI wrote a message too long, and it was cut short. Ask for a shorter one.'),
            default => __('The AI could not write the message.'),
        });
    }

    private function slotLogged(): bool
    {
        return WhatsappDelivery::query()
            ->where('whatsapp_message_id', $this->message->id)
            ->where('whatsapp_target_id', $this->target->id)
            ->where('scheduled_for', $this->slot)
            ->exists();
    }
}
