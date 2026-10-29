<?php

namespace App\Http\Controllers\Api\V1\Shopping;

use App\Actions\Shopping\CreateShoppingListSchedule;
use App\Actions\Shopping\DeleteShoppingListSchedule;
use App\Actions\Shopping\UpdateShoppingListSchedule;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingListScheduleResource;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The recurring shopping lists in the apps — of every workspace where the
 * user has the `shopping` module — through the same Actions as the web's
 * "Recurring" tab. The input: `name`, `recurrence` (the input of
 * SaveRecurrence; each run is a list's deadline, its errors under
 * `recurrence.*`), `next_list_content`, `closes_automatically`,
 * `waits_for_close`, `template_items` and `workspace` (where a new one goes,
 * or where one moves), as SavesShoppingListSchedule reads them. A schedule
 * of another workspace is not found (404).
 */
class ShoppingListScheduleController extends Controller
{
    /**
     * `GET /api/v1/shopping/schedules`: by name, as on the web.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $schedules = ShoppingListSchedule::query()
            ->visibleTo($this->actor($request))
            ->with(['recurrence.frequency', 'recurrence.weekdays', 'workspace'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return ShoppingListScheduleResource::collection($schedules);
    }

    /**
     * `POST /api/v1/shopping/schedules`: makes its first list too.
     */
    public function store(Request $request, CreateShoppingListSchedule $createSchedule): JsonResponse
    {
        $schedule = $createSchedule($this->actor($request), $this->input($request));

        return $this->scheduleResource($schedule)->response()->setStatusCode(201);
    }

    /**
     * `GET /api/v1/shopping/schedules/{schedule}`.
     */
    public function show(Request $request, ShoppingListSchedule $schedule): ShoppingListScheduleResource
    {
        if (! $schedule->isVisibleTo($this->actor($request))) {
            throw (new ModelNotFoundException)->setModel(ShoppingListSchedule::class, [$schedule->getKey()]);
        }

        return $this->scheduleResource($schedule);
    }

    /**
     * `PATCH /api/v1/shopping/schedules/{schedule}`: the whole schedule, as
     * the web form sends it; its open list moves to the new rule.
     */
    public function update(Request $request, ShoppingListSchedule $schedule, UpdateShoppingListSchedule $updateSchedule): ShoppingListScheduleResource
    {
        return $this->scheduleResource($updateSchedule($this->actor($request), $schedule, $this->input($request)));
    }

    /**
     * `DELETE /api/v1/shopping/schedules/{schedule}`: no list is made
     * anymore; the ones it made stay.
     */
    public function destroy(Request $request, ShoppingListSchedule $schedule, DeleteShoppingListSchedule $deleteSchedule): Response
    {
        $deleteSchedule($this->actor($request), $schedule);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        $input = $request->only(['name', 'recurrence', 'next_list_content', 'closes_automatically', 'waits_for_close', 'template_items', 'workspace']);

        // Only a list closed by hand can hold the next one back, as on the web.
        if (array_key_exists('waits_for_close', $input) && $request->boolean('closes_automatically', true)) {
            $input['waits_for_close'] = false;
        }

        return $input;
    }

    private function scheduleResource(ShoppingListSchedule $schedule): ShoppingListScheduleResource
    {
        $schedule->unsetRelation('recurrence')->load(['recurrence.frequency', 'recurrence.weekdays', 'workspace']);

        return new ShoppingListScheduleResource($schedule);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
