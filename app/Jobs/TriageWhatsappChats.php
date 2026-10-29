<?php

namespace App\Jobs;

use App\Ai\Agents\WhatsappTriageAgent;
use App\Events\WhatsappChatsTriaged;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * The AI triages the chats RequestWhatsappChatTriage asked for, on the
 * `whatsapp` queue (WhatsappTriageAgent): each chat it answered for gets its
 * urgency and summary, and the message it read up to; every chat stops
 * waiting, and the owner's screen reloads the list (WhatsappChatsTriaged).
 * Only the `ai_*` columns are written, never the user's `priority`; a
 * chat read meanwhile gets nothing.
 *
 * A single attempt, with more time than the AI's own timeout; when it
 * fails, the chats stop waiting and the screen says so.
 */
#[Tries(1)]
#[Timeout(120)]
#[DeleteWhenMissingModels]
class TriageWhatsappChats implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $chatIds
     */
    public function __construct(
        public User $user,
        public WhatsappAccount $account,
        public array $chatIds,
    ) {
        $this->onQueue(DeliverWhatsappMessage::QUEUE);
    }

    public function handle(): void
    {
        $chats = $this->account->targets()->whereKey($this->chatIds)->get();

        // What the AI reads goes up to the latest message now.
        $readThrough = $chats->mapWithKeys(fn (WhatsappTarget $chat): array => [$chat->id => $chat->last_message_at]);

        $triaged = $chats->isEmpty() ? [] : WhatsappTriageAgent::forUser($this->user)->triage($chats);

        foreach ($chats->fresh() as $chat) {
            // A chat read while the AI was at it keeps nothing of it.
            $answer = $chat->isUnread() ? ($triaged[$chat->friendly_id] ?? null) : null;

            $chat->forceFill($answer === null ? ['ai_triage_requested_at' => null] : [
                'ai_urgency' => $answer['urgency'],
                'ai_summary' => $answer['summary'] !== '' ? $answer['summary'] : null,
                'ai_triaged_at' => now(),
                'ai_triaged_through' => $readThrough[$chat->id],
                'ai_triage_requested_at' => null,
            ])->save();
        }

        WhatsappChatsTriaged::dispatch($this->user->id, $this->account->friendly_id);
    }

    public function failed(Throwable $exception): void
    {
        WhatsappTarget::query()->whereKey($this->chatIds)->update(['ai_triage_requested_at' => null]);

        WhatsappChatsTriaged::dispatch($this->user->id, $this->account->friendly_id, failed: true);
    }
}
