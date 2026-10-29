<?php

namespace App\Livewire\Whatsapp;

use App\Actions\Templates\PreviewTemplate;
use App\Actions\Whatsapp\CancelWhatsappMessage;
use App\Actions\Whatsapp\DeleteWhatsappMessage;
use App\Actions\Whatsapp\PauseWhatsappMessage;
use App\Actions\Whatsapp\ResumeWhatsappMessage;
use App\Actions\Whatsapp\ScheduleWhatsappMessage;
use App\Actions\Whatsapp\SendWhatsappChatContent;
use App\Actions\Whatsapp\SendWhatsappNow;
use App\Actions\Whatsapp\UpdateWhatsappMessage;
use App\Actions\Whatsapp\WhatsappMessageFields;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\RecurrenceFrequency;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappConnectionStatus;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Templates\TemplatePreview;
use App\Services\Whatsapp\WhatsappTemplateSchema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Creates or edits a WhatsApp message (US-7.3; Workflow 10): the account it
 * goes from (the user's `open` ones), the targets — the account's contacts
 * and groups, found with a search, and numbers typed in E.164 —, the body in
 * `x-ui.template-editor` with its plain-text preview, and the schedule in
 * the recurrence picker (`once` by default, tomorrow at 09:00). Instead of
 * the body, the AI may write it at each run from a prompt (`withAi`); such
 * a message is not sent now. Or it sends a poll (`withPoll`): its question,
 * 2 to 10 options and whether each person picks more than one.
 *
 * "Send now" (US-7.5) sends what the form holds right away through
 * SendWhatsappNow, saved or not, without touching the schedule. A saved
 * message also shows its state and next run, and can be paused, resumed,
 * cancelled (a `once` message before its time) and deleted.
 *
 * Only the author opens a saved message (403 otherwise).
 *
 * @property-read WhatsappMessage|null $message
 * @property-read Collection<int, WhatsappAccount> $accounts
 * @property-read Collection<int, WhatsappTarget> $targetOptions
 * @property-read Collection<int, WhatsappTarget> $chosenTargets
 * @property-read TemplatePreview|null $bodyPreview
 * @property-read WhatsappTemplateSchema $schema
 */
#[Layout('layouts::app-shell')]
class MessageForm extends Component
{
    use WithFileUploads;

    /**
     * How many contacts and groups the picker lists at a time.
     */
    public const int TARGET_OPTIONS_LIMIT = 30;

    /**
     * The friendly id of the message being edited; null while creating.
     */
    #[Locked]
    public ?string $messageId = null;

    public string $account = '';

    /**
     * The friendly ids of the chosen contacts and groups.
     *
     * @var list<string>
     */
    public array $selectedTargets = [];

    /**
     * Numbers typed in E.164.
     *
     * @var list<string>
     */
    public array $numbers = [];

    public string $newNumber = '';

    public string $targetSearch = '';

    public string $body = '';

    /** A file to send instead, the body its caption: attached, or a voice note recorded in the browser. */
    public ?TemporaryUploadedFile $file = null;

    /** Whether the file the message sends is dropped on save. */
    public bool $removesMedia = false;

    /** Whether the AI writes the message at each run, from `aiPrompt`, instead of the body. */
    public bool $withAi = false;

    public string $aiPrompt = '';

    /** Whether it sends a poll instead of a text. */
    public bool $withPoll = false;

    public string $pollQuestion = '';

    /**
     * @var list<string>
     */
    public array $pollOptions = ['', ''];

    public bool $pollMultiple = false;

    public RecurrencePicker $recurrence;

    public ?string $status = null;

    public function mount(?WhatsappMessage $message = null): void
    {
        if ($message === null || ! $message->exists) {
            $this->recurrence->load($this->actor()->timezone);
            $this->recurrence->frequency = RecurrenceFrequency::ONCE;
            $this->recurrence->starts_at = now()->setTimezone($this->recurrence->timezone)->addDay()->setTime(9, 0)->format(RecurrencePicker::INPUT_DATETIME_FORMAT);
            $this->account = (string) $this->accounts->first()?->friendly_id;

            return;
        }

        Gate::authorize('view', $message);

        $this->messageId = $message->friendly_id;
        $this->fillFrom($message);
    }

    #[Computed]
    public function message(): ?WhatsappMessage
    {
        return $this->messageId === null
            ? null
            : WhatsappMessage::query()->with(['recurrence.frequency', 'recurrence.weekdays', 'account'])->where('friendly_id', $this->messageId)->firstOrFail();
    }

    #[Computed]
    public function schema(): WhatsappTemplateSchema
    {
        return new WhatsappTemplateSchema;
    }

    /**
     * The user's connected accounts; the message's own account stays listed
     * even when it is not connected, so the form shows it.
     *
     * @return Collection<int, WhatsappAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $currentAccountId = $this->message?->whatsapp_account_id;

        return $this->actor()->whatsappAccounts()
            ->with('status')
            ->where(fn (Builder $query) => $query
                ->whereRelation('status', 'slug', WhatsappConnectionStatus::OPEN)
                ->when($currentAccountId !== null, fn (Builder $query) => $query->orWhere('id', $currentAccountId)))
            ->orderBy('nickname')
            ->orderBy('id')
            ->get();
    }

    /**
     * The contacts and groups of the chosen account matching the search, by
     * name or number, not chosen yet.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function targetOptions(): Collection
    {
        $account = $this->selectedAccount();

        if ($account === null) {
            return new Collection;
        }

        $search = trim($this->targetSearch);

        return $account->targets()
            ->with('type')
            ->whereNotIn('friendly_id', $this->selectedTargets)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('jid', '%'.ltrim($search, '+').'%')))
            ->orderBy('whatsapp_target_type_id')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::TARGET_OPTIONS_LIMIT)
            ->get();
    }

    /**
     * The chosen contacts and groups.
     *
     * @return Collection<int, WhatsappTarget>
     */
    #[Computed]
    public function chosenTargets(): Collection
    {
        $account = $this->selectedAccount();

        if ($account === null || $this->selectedTargets === []) {
            return new Collection;
        }

        return $account->targets()->with('type')->whereIn('friendly_id', $this->selectedTargets)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The body as a target named like the example would get it, as plain
     * text, or its problems.
     */
    #[Computed]
    public function bodyPreview(): ?TemplatePreview
    {
        return trim($this->body) === '' ? null : app(PreviewTemplate::class)($this->schema, $this->body, $this->actor());
    }

    /**
     * The chosen targets belong to the previous account.
     */
    public function updatedAccount(): void
    {
        $this->selectedTargets = [];
        $this->targetSearch = '';
        unset($this->targetOptions, $this->chosenTargets);
    }

    public function addTarget(string $targetId): void
    {
        if (! in_array($targetId, $this->selectedTargets, true)) {
            $this->selectedTargets[] = $targetId;
        }

        unset($this->targetOptions, $this->chosenTargets);
    }

    public function removeTarget(string $targetId): void
    {
        $this->selectedTargets = array_values(array_diff($this->selectedTargets, [$targetId]));

        unset($this->targetOptions, $this->chosenTargets);
    }

    public function addNumber(): void
    {
        $number = (string) preg_replace('/[\s\-().]/', '', $this->newNumber);

        $this->resetErrorBag('newNumber');

        if (preg_match(WhatsappMessageFields::E164_PATTERN, $number) !== 1) {
            $this->addError('newNumber', __('Type the number in international format, such as +5511999990000.'));

            return;
        }

        if (! in_array($number, $this->numbers, true)) {
            $this->numbers[] = $number;
        }

        $this->newNumber = '';
    }

    public function removeNumber(int $index): void
    {
        $numbers = $this->numbers;
        unset($numbers[$index]);
        $this->numbers = array_values($numbers);
    }

    public function addPollOption(): void
    {
        if (count($this->pollOptions) < SendWhatsappChatContent::MAX_POLL_OPTIONS) {
            $this->pollOptions = [...$this->pollOptions, ''];
        }
    }

    public function removePollOption(int $index): void
    {
        if (count($this->pollOptions) > SendWhatsappChatContent::MIN_POLL_OPTIONS) {
            $options = $this->pollOptions;
            unset($options[$index]);
            $this->pollOptions = array_values($options);
        }
    }

    public function save(ScheduleWhatsappMessage $scheduleMessage, UpdateWhatsappMessage $updateMessage): void
    {
        $this->resetErrorBag();

        if ($this->withAi && ! $this->withPoll) {
            $this->validate(['aiPrompt' => ['required', 'string']], attributes: ['aiPrompt' => __('prompt')]);
        }

        try {
            if ($this->message === null) {
                $saved = $scheduleMessage($this->actor(), $this->input());

                session()->flash('whatsapp-message-status', __('Message scheduled.'));
                $this->redirectRoute('whatsapp.messages.edit', $saved, navigate: true);

                return;
            }

            $updateMessage($this->actor(), $this->message, $this->input());
        } catch (ValidationException $exception) {
            throw $this->onFormFields($exception);
        }

        $this->reset('file', 'removesMedia');
        $this->status = __('Message saved.');
        unset($this->message);
    }

    /**
     * Sends what the form holds now, without scheduling anything.
     */
    public function sendNow(SendWhatsappNow $sendNow): void
    {
        $this->resetErrorBag();

        try {
            $deliveries = $sendNow($this->actor(), $this->sendingInput());
        } catch (ValidationException $exception) {
            throw $this->onFormFields($exception);
        }

        $this->status = trans_choice('{1} Sending to 1 recipient. Follow it in the history.|[2,*] Sending to :count recipients. Follow them in the history.', $deliveries->count(), ['count' => $deliveries->count()]);
    }

    public function removeMedia(): void
    {
        $this->removesMedia = true;
    }

    public function pause(PauseWhatsappMessage $pauseMessage): void
    {
        if ($this->message?->recurrence !== null) {
            $pauseMessage($this->actor(), $this->message);
            $this->status = __('Message paused. Nothing is sent until you resume it.');
        }

        unset($this->message);
    }

    public function resume(ResumeWhatsappMessage $resumeMessage): void
    {
        if ($this->message?->recurrence !== null) {
            $resumeMessage($this->actor(), $this->message);
            $this->status = __('Message resumed. The runs missed while paused are skipped.');
        }

        unset($this->message);
    }

    public function cancel(CancelWhatsappMessage $cancelMessage): void
    {
        if ($this->message === null) {
            return;
        }

        $cancelMessage($this->actor(), $this->message);

        $this->status = __('Message cancelled. It will not be sent.');
        unset($this->message);
    }

    public function delete(DeleteWhatsappMessage $deleteMessage): void
    {
        if ($this->message === null) {
            return;
        }

        $deleteMessage($this->actor(), $this->message);

        $this->redirectRoute('whatsapp.messages.index', navigate: true);
    }

    /**
     * The input of ScheduleWhatsappMessage and UpdateWhatsappMessage.
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return [
            ...$this->sendingInput(),
            'ai_prompt' => $this->withAi && ! $this->withPoll ? $this->aiPrompt : null,
            'file' => $this->withAi || $this->withPoll ? null : $this->file,
            'remove_media' => $this->removesMedia,
            'recurrence' => $this->recurrence->data(),
        ];
    }

    public function render(): View
    {
        $this->status ??= session('whatsapp-message-status');

        return view('livewire.whatsapp.message-form')
            ->title($this->messageId === null ? __('New WhatsApp message') : __('WhatsApp message'));
    }

    /**
     * @return array{account: string, targets: list<string>, numbers: list<string>, body: string, poll: array{question: string, options: list<string>, multiple: bool}|null}
     */
    private function sendingInput(): array
    {
        return [
            'account' => $this->account,
            'targets' => $this->selectedTargets,
            'numbers' => $this->numbers,
            'body' => $this->withPoll ? '' : $this->body,
            'poll' => $this->withPoll ? ['question' => $this->pollQuestion, 'options' => $this->pollOptions, 'multiple' => $this->pollMultiple] : null,
        ];
    }

    /**
     * The errors of the actions under the form's properties: `ai_prompt`
     * as `aiPrompt`, `poll.question` as `pollQuestion`, `poll.options.*` as
     * `pollOptions.*`.
     */
    private function onFormFields(ValidationException $exception): ValidationException
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            $key = match (true) {
                $field === 'ai_prompt' => 'aiPrompt',
                $field === 'poll' || $field === 'poll.question' => 'pollQuestion',
                str_starts_with($field, 'poll.options') => 'pollOptions'.substr($field, strlen('poll.options')),
                default => $field,
            };
            $errors[$key] = [...($errors[$key] ?? []), ...$messages];
        }

        return ValidationException::withMessages($errors);
    }

    private function selectedAccount(): ?WhatsappAccount
    {
        return $this->account === ''
            ? null
            : $this->actor()->whatsappAccounts()->where('friendly_id', $this->account)->first();
    }

    private function fillFrom(WhatsappMessage $message): void
    {
        $message->loadMissing(['account', 'targets', 'recurrence.frequency', 'recurrence.weekdays']);

        $this->account = $message->account->friendly_id;
        $this->selectedTargets = array_values($message->targets->sortBy('id')->pluck('friendly_id')->all());
        $this->body = $message->body;
        $this->withAi = $message->writesWithAi();
        $this->aiPrompt = (string) $message->ai_prompt;
        $this->withPoll = $message->sendsPoll();

        if ($message->poll !== null) {
            $this->pollQuestion = $message->poll['name'];
            $this->pollOptions = $message->poll['options'];
            $this->pollMultiple = $message->poll['selectable_count'] !== 1;
        }
        $this->recurrence->load($message->ownerTimezone(), $message->recurrence);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
