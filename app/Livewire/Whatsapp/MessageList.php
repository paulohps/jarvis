<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\SendWhatsappNow;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The user's own WhatsApp messages (US-7.3): each with the account it goes
 * from, how many recipients, its state (active, paused, cancelled or
 * finished) and its next run in the reader's timezone, opening its form.
 *
 * Each row has the quick action "Send now" (US-7.5), which sends the saved
 * message right away through SendWhatsappNow without touching its
 * schedule. The way to a new message, the history and the accounts is at
 * the top.
 *
 * @property-read Collection<int, WhatsappMessage> $messages
 */
#[Layout('layouts::app-shell')]
class MessageList extends Component
{
    public ?string $status = null;

    public ?string $error = null;

    /**
     * @return Collection<int, WhatsappMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        return WhatsappMessage::query()
            ->where('user_id', $this->user()->id)
            ->with(['account', 'recurrence.frequency'])
            ->withCount('targets')
            ->latest('id')
            ->get();
    }

    public function sendNow(string $messageId, SendWhatsappNow $sendNow): void
    {
        $message = WhatsappMessage::query()->with('account')->where('friendly_id', $messageId)->firstOrFail();

        Gate::authorize('update', $message);

        $this->status = null;
        $this->error = null;

        try {
            $deliveries = $sendNow($this->user(), $message->sendingInput());
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        }

        $this->status = trans_choice('{1} Sending to 1 recipient. Follow it in the history.|[2,*] Sending to :count recipients. Follow them in the history.', $deliveries->count(), ['count' => $deliveries->count()]);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.message-list')->title(__('WhatsApp messages'));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
