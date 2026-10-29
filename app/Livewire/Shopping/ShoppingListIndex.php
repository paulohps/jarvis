<?php

namespace App\Livewire\Shopping;

use App\Actions\Shopping\CreateShoppingList;
use App\Actions\Shopping\CreateShoppingListSchedule;
use App\Actions\Shopping\DeleteShoppingListSchedule;
use App\Actions\Shopping\UpdateShoppingListSchedule;
use App\Livewire\Forms\RecurrencePicker;
use App\Models\Module;
use App\Models\RecurrenceFrequency;
use App\Models\ShoppingList;
use App\Models\ShoppingListNextContent;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Models\Weekday;
use App\Models\WorkspaceMembership;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The shopping lists of the user's workspaces (US-8.1): the active ones on
 * the main screen and the archived ones under "Archived", each with how
 * many items are still to pick. A new list is named in a sheet and opens
 * right away.
 *
 * Under "Recurring", the lists made again on a schedule ("the market, every
 * Friday"): each run is a list's deadline. Their sheet sets the rule,
 * whether a list closes by itself at its deadline or only by hand — and
 * then whether the next one waits for it — and what the next list starts
 * with (ShoppingListNextContent), with the template's items one per line.
 *
 * A user with the `shopping` module in several workspaces sees all of
 * their lists together, each marked with its workspace's color, and picks
 * the workspace in the sheets (their default one preselected); a user in
 * one workspace sees none of it.
 *
 * @property-read Collection<int, ShoppingList> $lists
 * @property-read Collection<int, ShoppingListSchedule> $schedules
 * @property-read array<string, string> $workspaceOptions
 */
#[Layout('layouts::app-shell')]
class ShoppingListIndex extends Component
{
    public const string FILTER_ACTIVE = 'active';

    public const string FILTER_ARCHIVED = 'archived';

    public const string FILTER_RECURRING = 'recurring';

    public const array FILTERS = [self::FILTER_ACTIVE, self::FILTER_RECURRING, self::FILTER_ARCHIVED];

    #[Url]
    public string $filter = self::FILTER_ACTIVE;

    public bool $showForm = false;

    public string $name = '';

    /**
     * The workspace (friendly id) of the new list.
     */
    public string $workspace = '';

    public bool $showScheduleForm = false;

    #[Locked]
    public ?string $editingScheduleId = null;

    public string $scheduleName = '';

    /**
     * The workspace (friendly id) of the recurring list.
     */
    public string $scheduleWorkspace = '';

    public string $next_list_content = 'pending';

    public bool $closes_automatically = true;

    public bool $waits_for_close = false;

    /**
     * The template's items, one per line.
     */
    public string $template = '';

    public RecurrencePicker $recurrence;

    public ?string $status = null;

    public function mount(): void
    {
        $this->normalizeFilter();
        $this->resetScheduleForm();
    }

    /**
     * The lists of the current filter: active ones by name, archived ones
     * the latest archived first.
     *
     * @return Collection<int, ShoppingList>
     */
    #[Computed]
    public function lists(): Collection
    {
        $query = ShoppingList::query()->visibleTo($this->actor())->withItemCounts()->with('workspace');

        return $this->filter === self::FILTER_ARCHIVED
            ? $query->archived()->latest('archived_at')->latest('id')->get()
            : $query->notArchived()->orderByRaw('due_at asc nulls last')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The recurring lists by name, each with its open list.
     *
     * @return Collection<int, ShoppingListSchedule>
     */
    #[Computed]
    public function schedules(): Collection
    {
        return ShoppingListSchedule::query()
            ->visibleTo($this->actor())
            ->with(['recurrence.frequency', 'recurrence.weekdays', 'createdBy', 'workspace'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * The user's workspaces with the `shopping` module, by name: friendly id
     * => name.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function workspaceOptions(): array
    {
        return $this->actor()->membershipsWithModule(Module::SHOPPING)
            ->mapWithKeys(fn (WorkspaceMembership $membership): array => [$membership->workspace->friendly_id => $membership->workspace->name])
            ->all();
    }

    /**
     * Whether the workspaces show: only for a user with the module in more
     * than one.
     */
    public function showsWorkspaces(): bool
    {
        return count($this->workspaceOptions) > 1;
    }

    /**
     * How a recurring list repeats: "Weekly · Fri · 22:00".
     */
    public function ruleLabel(ShoppingListSchedule $schedule): string
    {
        $recurrence = $schedule->recurrence;

        if ($recurrence === null) {
            return '';
        }

        $slugs = $recurrence->weekdays->map(fn (Weekday $weekday): string => $weekday->slug)->all();
        $weekdays = collect($this->recurrence->weekdayOptions())
            ->filter(fn (string $label, string $slug): bool => in_array($slug, $slugs, true))
            ->join(', ');

        return collect([
            $this->recurrence->frequencyOptions()[$recurrence->frequency->slug] ?? null,
            $weekdays,
            $recurrence->time_of_day,
        ])->filter()->join(' · ');
    }

    /**
     * @return array<string, string>
     */
    public function nextContentOptions(): array
    {
        return [
            ShoppingListNextContent::Pending->value => __('The items left unchecked'),
            ShoppingListNextContent::All->value => __('All the items of the last list'),
            ShoppingListNextContent::TemplateAndPending->value => __('The template, plus the items left unchecked'),
            ShoppingListNextContent::Empty->value => __('Nothing'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        return [
            self::FILTER_ACTIVE => __('Lists'),
            self::FILTER_RECURRING => __('Recurring'),
            self::FILTER_ARCHIVED => __('Archived'),
        ];
    }

    public function updatedFilter(): void
    {
        $this->normalizeFilter();
        unset($this->lists, $this->schedules);
    }

    public function createSchedule(): void
    {
        $this->resetScheduleForm();
        $this->showScheduleForm = true;
    }

    public function editSchedule(string $scheduleId): void
    {
        $schedule = $this->findSchedule($scheduleId);
        $schedule->load(['recurrence.frequency', 'recurrence.weekdays', 'createdBy']);

        $this->resetScheduleForm();
        $this->editingScheduleId = $schedule->friendly_id;
        $this->scheduleName = $schedule->name;
        $this->scheduleWorkspace = $schedule->workspace->friendly_id;
        $this->next_list_content = $schedule->next_list_content->value;
        $this->closes_automatically = $schedule->closes_automatically;
        $this->waits_for_close = $schedule->waits_for_close;
        $this->template = implode("\n", $schedule->template_items ?? []);
        $this->recurrence->load($schedule->ownerTimezone(), $schedule->recurrence);
        $this->showScheduleForm = true;
    }

    public function saveSchedule(CreateShoppingListSchedule $createSchedule, UpdateShoppingListSchedule $updateSchedule): void
    {
        $input = [
            'name' => $this->scheduleName,
            'recurrence' => $this->recurrence->data(),
            'next_list_content' => $this->next_list_content,
            'closes_automatically' => $this->closes_automatically,
            'waits_for_close' => ! $this->closes_automatically && $this->waits_for_close,
            'template_items' => preg_split('/\R/', $this->template) ?: [],
            'workspace' => $this->showsWorkspaces() ? $this->scheduleWorkspace : null,
        ];

        try {
            $schedule = $this->editingScheduleId === null
                ? $createSchedule($this->actor(), $input)
                : $updateSchedule($this->actor(), $this->findSchedule($this->editingScheduleId), $input);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors[match (true) {
                    $field === 'name' => 'scheduleName',
                    $field === 'workspace' => 'scheduleWorkspace',
                    str_starts_with($field, 'template_items') => 'template',
                    default => $field,
                }] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        $this->status = __('Recurring list “:name” saved.', ['name' => $schedule->name]);
        $this->resetScheduleForm();
        unset($this->schedules, $this->lists);
    }

    public function deleteSchedule(string $scheduleId, DeleteShoppingListSchedule $deleteSchedule): void
    {
        $schedule = $this->findSchedule($scheduleId);

        $deleteSchedule($this->actor(), $schedule);

        $this->status = __('Recurring list “:name” stopped.', ['name' => $schedule->name]);
        $this->resetScheduleForm();
        unset($this->schedules);
    }

    public function create(): void
    {
        $this->reset('name');
        $this->workspace = $this->defaultWorkspaceId();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(CreateShoppingList $createShoppingList): void
    {
        $this->resetErrorBag();

        $list = $createShoppingList($this->actor(), [
            'name' => $this->name,
            'workspace' => $this->showsWorkspaces() ? $this->workspace : null,
        ]);

        $this->reset('showForm', 'name', 'workspace');

        $this->redirectRoute('shopping.show', ['list' => $list], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.shopping.shopping-list-index')->title(__('Shopping list'));
    }

    private function resetScheduleForm(): void
    {
        $this->reset('showScheduleForm', 'editingScheduleId', 'scheduleName', 'next_list_content', 'closes_automatically', 'waits_for_close', 'template');
        $this->scheduleWorkspace = $this->defaultWorkspaceId();
        $this->recurrence->load($this->actor()->timezone);
        $this->recurrence->frequency = RecurrenceFrequency::WEEKLY;
        $this->recurrence->time_of_day = '22:00';
        $this->resetValidation();
    }

    private function findSchedule(string $scheduleId): ShoppingListSchedule
    {
        return ShoppingListSchedule::query()->visibleTo($this->actor())->where('friendly_id', $scheduleId)->firstOrFail();
    }

    /**
     * The friendly id of the user's default workspace for shopping, '' when
     * they have none.
     */
    private function defaultWorkspaceId(): string
    {
        return $this->actor()->defaultWorkspaceFor(Module::SHOPPING)?->friendly_id ?? '';
    }

    private function normalizeFilter(): void
    {
        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = self::FILTER_ACTIVE;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
