<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappChatMessage;
use App\Models\WhatsappTarget;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * What the composer of an open chat sends besides a text or a file, each
 * in its own dialog (SendWhatsappChatContent): a poll, a location (typed,
 * or where the browser is), contact cards of the number's contacts, or an
 * image as a sticker. The chat asks for one with `whatsapp-compose`
 * (`kind`, and the message answered, if any), and is told it went with
 * `whatsapp-content-sent`.
 *
 * A poll may be scheduled instead (`schedules`): once or repeating, as the
 * recurrence picker says, as a scheduled message to the chat
 * (ScheduleWhatsappMessage), without the message answered. The chat is
 * told with `whatsapp-poll-scheduled`.
 *
 * Only the chats of the user's numbers.
 *
 * @property-read WhatsappTarget $chat
 * @property-read Collection<int, WhatsappTarget> $contacts
 */
class ChatContent extends Component
{
    use WithFileUploads;

    public const string KIND_POLL = 'poll';

    public const string KIND_LOCATION = 'location';

    public const string KIND_CONTACTS = 'contacts';

    public const string KIND_STICKER = 'sticker';

    /**
     * @var list<string>
     */
    public const array KINDS = [self::KIND_POLL, self::KIND_LOCATION, self::KIND_CONTACTS, self::KIND_STICKER];

    public const int CONTACTS_SHOWN = 50;

    #[Locked]
    public string $chatId;

    #[Locked]
    public ?string $replyToId = null;

    public string $kind = '';

    public bool $show = false;

    public string $question = '';

    /**
     * @var list<string>
     */
    public array $options = ['', ''];

    public bool $multiple = false;

    public string $latitude = '';

    public string $longitude = '';

    public string $placeName = '';

    public string $address = '';

    public string $contactSearch = '';

    /**
     * @var list<string>
     */
    public array $pickedContacts = [];

    public ?TemporaryUploadedFile $sticker = null;

    /** Whether the poll is scheduled instead of sent now. */
    public bool $schedules = false;

    public RecurrencePicker $recurrence;

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

    /**
     * The number's contacts with a phone number, by name, the search
     * narrowing them.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function contacts(): Collection
    {
        $search = mb_trim($this->contactSearch);

        return $this->chat->account->targets()
            ->where('jid', 'like', '%'.WhatsappTarget::CONTACT_JID_SUFFIX)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('nickname', '%'.$search.'%')
                ->when(preg_replace('/\D/', '', $search) !== '', fn ($query) => $query->orWhereLike('jid', '%'.preg_replace('/\D/', '', $search).'%'))))
            ->orderByRaw('coalesce(nickname, name, jid)')
            ->limit(self::CONTACTS_SHOWN)
            ->get();
    }

    /**
     * Opens the dialog of one kind of content.
     */
    #[On('whatsapp-compose')]
    public function compose(string $kind, ?string $replyToId = null): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            return;
        }

        $this->reset('question', 'options', 'multiple', 'latitude', 'longitude', 'placeName', 'address', 'contactSearch', 'pickedContacts', 'sticker', 'schedules');
        $this->resetErrorBag();
        $this->recurrence->load($this->user()->timezone);
        $this->recurrence->frequency = RecurrenceFrequency::ONCE;
        $this->recurrence->starts_at = now()->setTimezone($this->recurrence->timezone)->addDay()->setTime(9, 0)->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
        $this->kind = $kind;
        $this->replyToId = $replyToId;
        $this->show = true;
    }

    public function addOption(): void
    {
        if (count($this->options) < SendWhatsappChatContent::MAX_POLL_OPTIONS) {
            $this->options[] = '';
        }
    }

    public function removeOption(int $index): void
    {
        if (count($this->options) > SendWhatsappChatContent::MIN_POLL_OPTIONS) {
            unset($this->options[$index]);
            $this->options = array_values($this->options);
        }
    }

    public function send(SendWhatsappChatContent $send, ScheduleWhatsappMessage $schedule): void
    {
        $this->resetErrorBag();

        if ($this->kind === self::KIND_POLL && $this->schedules) {
            $this->schedulePoll($schedule);

            return;
        }

        $replyTo = $this->replyTo();

        match ($this->kind) {
            self::KIND_POLL => $send->poll($this->user(), $this->chat, $this->question, $this->options, $this->multiple, $replyTo),
            self::KIND_LOCATION => $send->location($this->user(), $this->chat, $this->coordinate('latitude'), $this->coordinate('longitude'), $this->placeName, $this->address, $replyTo),
            self::KIND_CONTACTS => $send->contacts($this->user(), $this->chat, $this->pickedContacts),
            self::KIND_STICKER => $this->sticker !== null
                ? $send->sticker($this->user(), $this->chat, $this->sticker, $replyTo)
                : $this->addError('sticker', __('Pick an image.')),
            default => null,
        };

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->reset('show', 'kind', 'replyToId', 'sticker');
        $this->dispatch('whatsapp-content-sent');
    }

    public function render(): View
    {
        return view('livewire.whatsapp.chat-content');
    }

    /**
     * Schedules the poll to the chat; the errors of the action under the
     * dialog's fields (`schedule` for the rest, such as a number not
     * connected).
     */
    private function schedulePoll(ScheduleWhatsappMessage $schedule): void
    {
        try {
            $schedule($this->user(), [
                'account' => $this->chat->account->friendly_id,
                'targets' => [$this->chat->friendly_id],
                'poll' => ['question' => $this->question, 'options' => $this->options, 'multiple' => $this->multiple],
                'recurrence' => $this->recurrence->data(),
            ]);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $key = match (true) {
                    str_starts_with($field, 'recurrence.') => $field,
                    $field === 'poll.question' => 'question',
                    str_starts_with($field, 'poll.options') => substr($field, strlen('poll.')),
                    default => 'schedule',
                };
                $errors[$key] = [...($errors[$key] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }

        $this->reset('show', 'kind', 'replyToId', 'schedules');
        $this->dispatch('whatsapp-poll-scheduled');
    }

    private function replyTo(): ?WhatsappChatMessage
    {
        return $this->replyToId === null ? null : $this->chat->chatMessages()->where('friendly_id', $this->replyToId)->first();
    }

    /**
     * A coordinate typed with a comma or a dot; out of range when it is no
     * number, so the action tells it.
     */
    private function coordinate(string $property): float
    {
        $value = str_replace(',', '.', mb_trim($this->{$property}));

        return is_numeric($value) ? (float) $value : 1000.0;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
