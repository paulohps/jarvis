<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\ContinueWhatsappDraftConversation;
use App\Actions\Whatsapp\EditWhatsappDraftMessage;
use App\Actions\Whatsapp\ListWhatsappGroupParticipants;
use App\Exceptions\AiAnswerCutShort;
use App\Exceptions\AiProviderNotConfigured;
use App\Exceptions\EvolutionApiException;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappDraftConversation;
use App\Models\WhatsappDraftMessage;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * The AI's side panel of an open chat: a conversation with the AI about
 * what to write in it (ContinueWhatsappDraftConversation). The owner asks,
 * the AI writes the message in their style, the owner asks for changes or
 * edits it by hand (EditWhatsappDraftMessage), and "Use message" puts the
 * one they like in the composer (`whatsapp-use-draft`). The conversations
 * of the chat are kept, named by the AI, to be continued later.
 *
 * The chat opens it with `whatsapp-draft`, always on a new conversation
 * (the past ones are in the history), with the messages picked for the AI
 * to answer, if any; the AI reads the chat unless the owner turns it off
 * (`readsChat`). A message sent from the AI opens its conversation
 * (`conversationId`, `sentMessageId`): the message the AI writes then
 * goes to the dialog that edits the sent one (`whatsapp-edit-sent`), while
 * WhatsApp still lets it be edited.
 *
 * Only the chats of the user's numbers, and the user's own conversations.
 *
 * @property-read WhatsappTarget $chat
 * @property-read WhatsappDraftConversation|null $conversation
 * @property-read Collection<int, WhatsappDraftConversation> $conversations
 * @property-read array<string, string> $mentionNames
 * @property-read WhatsappChatMessage|null $sentMessage
 */
class DraftAssistant extends Component
{
    public const int CONVERSATIONS_SHOWN = 30;

    #[Locked]
    public string $chatId;

    public bool $show = false;

    /** Whether the panel lists the past conversations instead of the open one. */
    public bool $showHistory = false;

    #[Locked]
    public ?string $conversationId = null;

    /** What the owner asks the AI next. */
    public string $request = '';

    /** Whether the AI reads the chat's latest messages. */
    public bool $readsChat = true;

    /**
     * The friendly ids of the messages the AI answers, picked in the chat.
     *
     * @var list<string>
     */
    #[Locked]
    public array $replyTo = [];

    /** The message sent from the conversation, when the panel was opened from it. */
    #[Locked]
    public ?string $sentMessageId = null;

    public ?string $error = null;

    /**
     * The members of the open group the prompt can mention, once asked for
     * (loadMentionables()): name and number.
     *
     * @var list<array{name: string, number: string}>
     */
    public array $mentionables = [];

    public function mount(string $chatId): void
    {
        $this->chatId = $chatId;
        $this->authorize('use', $this->chat->account);
    }

    #[Computed]
    public function chat(): WhatsappTarget
    {
        return WhatsappTarget::query()
            ->where('friendly_id', $this->chatId)
            ->whereHas('account', fn ($query) => $query->where('user_id', $this->user()->id))
            ->firstOrFail();
    }

    #[Computed]
    public function conversation(): ?WhatsappDraftConversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return $this->ownConversations()->where('friendly_id', $this->conversationId)->with('messages')->first();
    }

    /**
     * The user's conversations about the chat, the latest first.
     *
     * @return Collection<int, WhatsappDraftConversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->ownConversations()->latest('updated_at')->latest('id')->limit(self::CONVERSATIONS_SHOWN)->get();
    }

    /**
     * The names to show for the mentioned members, by `@` and number.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function mentionNames(): array
    {
        return collect($this->mentionables)->mapWithKeys(fn (array $member): array => ['@'.$member['number'] => $member['name']])->all();
    }

    /**
     * The message sent from the conversation, while it is of the chat.
     */
    #[Computed]
    public function sentMessage(): ?WhatsappChatMessage
    {
        if ($this->sentMessageId === null) {
            return null;
        }

        return $this->chat->chatMessages()->where('friendly_id', $this->sentMessageId)->where('from_me', true)->first()?->setRelation('target', $this->chat);
    }

    /**
     * Opens the panel on a new conversation about the picked messages, or on
     * the conversation a sent message came from.
     *
     * @param  list<string>  $replyTo
     */
    #[On('whatsapp-draft')]
    public function open(array $replyTo = [], ?string $conversationId = null, ?string $sentMessageId = null): void
    {
        $this->startNew();

        if ($conversationId !== null && $this->ownConversations()->where('friendly_id', $conversationId)->exists()) {
            $this->openConversation($conversationId);
            $this->sentMessageId = $sentMessageId;
            unset($this->sentMessage);
        } else {
            $this->replyTo = array_values(array_filter($replyTo, is_string(...)));
        }

        $this->show = true;
    }

    public function startNew(): void
    {
        $this->reset('conversationId', 'request', 'readsChat', 'replyTo', 'sentMessageId', 'error', 'showHistory');
        unset($this->conversation, $this->sentMessage);
    }

    public function openConversation(string $conversationId): void
    {
        $conversation = $this->ownConversations()->where('friendly_id', $conversationId)->firstOrFail();

        $this->startNew();
        $this->conversationId = $conversation->friendly_id;
        $this->readsChat = $conversation->reads_chat;
        $this->replyTo = $conversation->reply_to ?? [];
    }

    public function deleteConversation(string $conversationId): void
    {
        $this->ownConversations()->where('friendly_id', $conversationId)->firstOrFail()->delete();

        if ($conversationId === $this->conversationId) {
            $this->startNew();
            $this->showHistory = true;
        }

        unset($this->conversations);
    }

    public function forgetReplyTo(): void
    {
        $this->reset('replyTo');
    }

    /**
     * Asks the AI for the message, or for a change to the last one.
     */
    public function ask(ContinueWhatsappDraftConversation $continue): void
    {
        $this->reset('error');
        $this->resetErrorBag();

        try {
            $written = $continue($this->user(), $this->chat, $this->conversation, $this->request, $this->readsChat, $this->replyTo);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return;
        } catch (AiProviderNotConfigured) {
            $this->error = __('No AI is set up for WhatsApp replies. Ask the administrator to set one.');

            return;
        } catch (AiAnswerCutShort $exception) {
            report($exception);
            $this->error = __('The message came out too long and was cut short. Ask for a shorter one, or try again.');

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = __('The assistant could not write a reply right now. Try again in a moment.');

            return;
        }

        $this->conversationId = $written->conversation->friendly_id;
        $this->reset('request');
        unset($this->conversation, $this->conversations);
    }

    /**
     * Saves the owner's change to a message the AI wrote, made in its bubble.
     */
    public function saveEditing(EditWhatsappDraftMessage $edit, string $messageId, ?string $body): bool
    {
        try {
            $edit($this->user(), $this->ownMessage($messageId), $body);
        } catch (ValidationException $exception) {
            $this->error = collect($exception->errors())->flatten()->first();

            return false;
        }

        $this->reset('error');
        unset($this->conversation);

        return true;
    }

    /**
     * Puts a message the AI wrote in the chat's composer, or in the dialog
     * that edits the message sent from the conversation, and closes the
     * panel.
     */
    public function useMessage(string $messageId): void
    {
        $message = $this->ownMessage($messageId);

        if ($this->sentMessage?->canEdit()) {
            $this->dispatch('whatsapp-edit-sent', messageId: $this->sentMessage->friendly_id, text: $message->body);
        } else {
            $this->dispatch('whatsapp-use-draft', text: $message->body, draftMessageId: $message->friendly_id);
        }

        $this->show = false;
    }

    /**
     * The members of the open group, for the prompt to mention them.
     */
    public function loadMentionables(ListWhatsappGroupParticipants $listParticipants): void
    {
        if (! $this->chat->isGroup() || ! $this->chat->account->isOpen()) {
            return;
        }

        try {
            $members = $listParticipants($this->user(), $this->chat);
        } catch (EvolutionApiException $exception) {
            report($exception);

            return;
        }

        $this->mentionables = array_values(array_map(
            fn (array $member): array => ['name' => $member['name'], 'number' => ltrim((string) $member['number'], '+')],
            array_filter($members, fn (array $member): bool => ! $member['me'] && $member['number'] !== null),
        ));
        unset($this->mentionNames);
    }

    public function render(): View
    {
        return view('livewire.whatsapp.draft-assistant');
    }

    /**
     * @return Builder<WhatsappDraftConversation>
     */
    private function ownConversations(): Builder
    {
        return WhatsappDraftConversation::query()
            ->where('user_id', $this->user()->id)
            ->where('whatsapp_target_id', $this->chat->id);
    }

    /**
     * A message of the open conversation.
     */
    private function ownMessage(string $messageId): WhatsappDraftMessage
    {
        $conversation = $this->conversation ?? abort(404);

        return $conversation->messages->firstWhere('friendly_id', $messageId) ?? abort(404);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
