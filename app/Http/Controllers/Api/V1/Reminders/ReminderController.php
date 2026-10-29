<?php

namespace App\Http\Controllers\Api\V1\Reminders;

use App\Actions\Reminders\CreateReminder;
use App\Actions\Reminders\DeleteReminder;
use App\Actions\Reminders\UpdateReminder;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReminderAgendaEntryResource;
use App\Http\Resources\ReminderOccurrenceResource;
use App\Http\Resources\ReminderResource;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\AssigneeOptions;
use App\Services\Reminders\ReminderOccurrenceFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The reminders and tasks of the reader's workspaces in the apps (US-9.1),
 * through the same Actions as the web list. The input: `title`, `note`,
 * `due_at` (a date and time; without an offset it is read in the creator's
 * timezone), `recurrence` (the input of SaveRecurrence), `workspace` (the
 * friendly id of one of the reader's workspaces with the module: where a
 * new one goes — their default when left out — or where an edited one
 * moves) and `assigned_to` (the friendly id of who is responsible, a member
 * of that workspace, null for nobody; left out, the creator on a new
 * reminder and unchanged on an edit). A reminder of a workspace the reader
 * does not see is not found. Instants come back in ISO-8601 UTC, with the
 * reader's timezone in `meta.timezone`.
 *
 * Who may be made responsible depends on the workspace: `meta.assignees`
 * lists those of the reader's default workspace (of the reminder's own on
 * `show`) and `meta.assignees_by_workspace` those of each of theirs, by the
 * workspace's friendly id.
 */
class ReminderController extends Controller
{
    /**
     * `GET /api/v1/reminders?filter=pending|overdue|completed&mine=1`: the
     * occurrences of the filter, the same sets as the web (pending by
     * default) — only the reader's own with `mine` — with how many each
     * filter holds in `meta.counts` and who may be made responsible in
     * `meta.assignees` and `meta.assignees_by_workspace`.
     */
    public function index(Request $request, ReminderOccurrenceFeed $feed, AssigneeOptions $assignees): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'string', Rule::in(ReminderOccurrenceFeed::FILTERS)],
            'mine' => ['nullable', 'boolean'],
        ]);

        $filter = $validated['filter'] ?? ReminderOccurrenceFeed::PENDING;
        $actor = $this->actor($request);
        $assignee = $request->boolean('mine') ? $actor : null;

        return ReminderOccurrenceResource::collection($feed->list($actor, $filter, $assignee))
            ->additional(['meta' => [
                'filter' => $filter,
                'mine' => $assignee !== null,
                'counts' => $feed->counts($actor, $assignee),
                'assignees' => $assignees->ofDefault($actor),
                'assignees_by_workspace' => $assignees->byWorkspace($actor),
                'timezone' => $actor->timezone,
            ]]);
    }

    /**
     * `GET /api/v1/reminders/agenda?mine=1`: the open occurrences by day, as
     * the web's agenda — `overdue` first, each day (`Y-m-d` in the reader's
     * timezone) in order, `undated` last — with the coming runs of the
     * repeating reminders projected for the next
     * ReminderOccurrenceFeed::AGENDA_PROJECTION_DAYS.
     */
    public function agenda(Request $request, ReminderOccurrenceFeed $feed, AssigneeOptions $assignees): JsonResponse
    {
        $request->validate(['mine' => ['nullable', 'boolean']]);

        $actor = $this->actor($request);
        $assignee = $request->boolean('mine') ? $actor : null;
        $groups = [];

        foreach ($feed->agenda($actor, $assignee) as $key => $entries) {
            $groups[] = [
                'key' => $key,
                'date' => in_array($key, [ReminderOccurrenceFeed::AGENDA_OVERDUE, ReminderOccurrenceFeed::AGENDA_UNDATED], true) ? null : $key,
                'entries' => ReminderAgendaEntryResource::collection($entries)->resolve($request),
            ];
        }

        return response()->json([
            'data' => $groups,
            'meta' => [
                'mine' => $assignee !== null,
                'projection_days' => ReminderOccurrenceFeed::AGENDA_PROJECTION_DAYS,
                'assignees' => $assignees->ofDefault($actor),
                'assignees_by_workspace' => $assignees->byWorkspace($actor),
                'timezone' => $actor->timezone,
            ],
        ]);
    }

    /**
     * `POST /api/v1/reminders`.
     */
    public function store(Request $request, CreateReminder $createReminder): JsonResponse
    {
        $reminder = $createReminder($this->actor($request), $this->input($request));

        return $this->reminderResource($request, $reminder)->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/reminders/{reminder}`: one reminder or event, with its
     * open occurrence — the agenda's calendar names only its id — and who
     * may be made responsible in `meta.assignees` (of its workspace) and
     * `meta.assignees_by_workspace` (where it may be moved).
     */
    public function show(Request $request, Reminder $reminder, AssigneeOptions $assignees): ReminderResource
    {
        $actor = $this->actor($request);

        abort_unless($reminder->isVisibleTo($actor), 404);

        return $this->reminderResource($request, $reminder)->additional(['meta' => [
            'timezone' => $actor->timezone,
            'assignees' => $assignees->of($reminder->workspace),
            'assignees_by_workspace' => $assignees->byWorkspace($actor),
        ]]);
    }

    /**
     * `PATCH /api/v1/reminders/{reminder}`: the whole reminder, as on the
     * web form — a missing `due_at` and `recurrence` make it undated.
     */
    public function update(Request $request, Reminder $reminder, UpdateReminder $updateReminder): ReminderResource
    {
        return $this->reminderResource($request, $updateReminder($this->actor($request), $reminder, $this->input($request)));
    }

    /**
     * `DELETE /api/v1/reminders/{reminder}`.
     */
    public function destroy(Request $request, Reminder $reminder, DeleteReminder $deleteReminder): Response
    {
        $deleteReminder($this->actor($request), $reminder);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->only(['title', 'note', 'due_at', 'recurrence', 'assigned_to', 'kind', 'is_all_day', 'duration_minutes', 'workspace']);
    }

    private function reminderResource(Request $request, Reminder $reminder): ReminderResource
    {
        $reminder->load([
            'workspace',
            'createdBy',
            'assignedTo',
            'recurrence.frequency',
            'recurrence.weekdays',
            'occurrences' => fn ($query) => $query->whereNull('completed_at')->orderByRaw('due_at asc nulls last')->orderBy('id'),
        ]);

        return (new ReminderResource($reminder))->additional(['meta' => ['timezone' => $this->actor($request)->timezone]]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
