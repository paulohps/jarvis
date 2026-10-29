<?php

namespace App\Http\Controllers\Api\V1\Agenda;

use App\Http\Controllers\Controller;
use App\Http\Resources\CalendarEntryResource;
use App\Models\User;
use App\Services\Agenda\CalendarFeed;
use App\Services\Reminders\AssigneeOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The agenda's calendar for the apps (US-9.1): everything scheduled that
 * the reader may see, as on the web.
 */
class AgendaController extends Controller
{
    /**
     * `GET /api/v1/agenda?from=&to=&mine=&hide[]=`: the items starting in
     * [`from`, `to`) — instants, or dates read as midnight in the reader's
     * timezone — at most CalendarFeed::MAX_DAYS apart, all-day ones first in
     * each day. `mine` narrows the reminders and events to the reader's;
     * `hide` leaves sources out. Each entry of shared data names its
     * `workspace` (null for the personal sources), in the color the reader
     * sees it in. Who may be made responsible for a reminder goes in
     * `meta.assignees` (the reader's default workspace) and
     * `meta.assignees_by_workspace` (each of theirs), for the apps' form.
     */
    public function index(Request $request, CalendarFeed $feed, AssigneeOptions $assignees): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'mine' => ['nullable', 'boolean'],
            'hide' => ['nullable', 'array'],
            'hide.*' => ['string', Rule::in(CalendarFeed::SOURCES)],
        ]);

        $from = CarbonImmutable::parse($validated['from'], $actor->timezone)->utc();
        $to = CarbonImmutable::parse($validated['to'], $actor->timezone)->utc();

        if ($from->diffInDays($to) > CalendarFeed::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => [__('The period may span at most :days days.', ['days' => CalendarFeed::MAX_DAYS])],
            ]);
        }

        $assignee = $request->boolean('mine') ? $actor : null;

        /** @var list<string> $hidden */
        $hidden = array_values($validated['hide'] ?? []);

        return response()->json([
            'data' => CalendarEntryResource::collection($feed->between($actor, $from, $to, $assignee, $hidden))->resolve($request),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'mine' => $assignee !== null,
                'sources' => $feed->keysFor($actor),
                'assignees' => $assignees->ofDefault($actor),
                'assignees_by_workspace' => $assignees->byWorkspace($actor),
                'timezone' => $actor->timezone,
            ],
        ]);
    }
}
