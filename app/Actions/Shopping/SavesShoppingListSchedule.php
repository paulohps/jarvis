<?php

namespace App\Actions\Shopping;

use App\Actions\Recurrences\SaveRecurrence;
use App\Models\Module;
use App\Models\ShoppingList;
use App\Models\ShoppingListNextContent;
use App\Models\ShoppingListSchedule;
use App\Models\User;
use App\Services\Shopping\ShoppingListScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a recurring shopping list and its recurrence in one transaction,
 * for CreateShoppingListSchedule and UpdateShoppingListSchedule.
 *
 * The input: `name` (required, up to 100 characters, the start of each
 * list's name), `recurrence` (required, the input of SaveRecurrence; each
 * run is a list's deadline, its errors under `recurrence.*`),
 * `next_list_content` (a ShoppingListNextContent, `pending` when left out),
 * `closes_automatically` (at the deadline; true when left out),
 * `waits_for_close` (a list closed by hand only holds the next one back
 * until it is closed; false when left out) and `template_items` (up to 100
 * names, for `template_and_pending`) and `workspace` (a friendly id, one of
 * the actor's workspaces with the `shopping` module): a new schedule goes
 * there or, left out, to the actor's default one; a schedule changed with
 * one moves there, its open list with it (no longer the default, as the
 * other workspace may have its own). The lists it makes belong to its
 * workspace.
 *
 * Afterwards the schedule has its open list: made when it had none, or
 * moved — deadline and name — to the next run when it was still ahead.
 */
trait SavesShoppingListSchedule
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    private function persist(SaveRecurrence $saveRecurrence, ShoppingListScheduler $scheduler, User $actor, array $input, ?ShoppingListSchedule $schedule): ShoppingListSchedule
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = mb_trim($input['name']);
        }

        if (is_array($input['template_items'] ?? null)) {
            $input['template_items'] = array_values(array_filter(
                array_map(fn (mixed $name): mixed => is_string($name) ? mb_trim($name) : $name, $input['template_items']),
                // Blank lines are left out; through the API they arrive as null.
                fn (mixed $name): bool => $name !== '' && $name !== null,
            ));
        }

        /** @var array{name: string, recurrence: array<string, mixed>, next_list_content?: string|null, closes_automatically?: bool|null, waits_for_close?: bool|null, template_items?: list<string>|null} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'recurrence' => ['required', 'array'],
            'next_list_content' => ['nullable', Rule::enum(ShoppingListNextContent::class)],
            'closes_automatically' => ['nullable', 'boolean'],
            'waits_for_close' => ['nullable', 'boolean'],
            'template_items' => ['nullable', 'array', 'max:100'],
            'template_items.*' => ['string', 'max:255'],
        ], attributes: [
            'name' => __('name'),
            'recurrence' => __('repeat'),
            'next_list_content' => __('next list'),
            'template_items' => __('template'),
        ])->validate();

        $workspaceId = $schedule === null || filled($input['workspace'] ?? null)
            ? $this->workspaceFor($actor, $input, Module::SHOPPING)->id
            : $schedule->workspace_id;

        $schedule ??= new ShoppingListSchedule(['created_by_id' => $actor->id, 'workspace_id' => $workspaceId]);
        $schedule->setRelation('createdBy', $schedule->exists ? $schedule->createdBy : $actor);

        return DB::transaction(function () use ($saveRecurrence, $scheduler, $schedule, $validated, $workspaceId): ShoppingListSchedule {
            if ($schedule->exists && $schedule->workspace_id !== $workspaceId) {
                ShoppingList::query()
                    ->where('shopping_list_schedule_id', $schedule->id)
                    ->whereNull('completed_at')
                    ->notArchived()
                    ->update([
                        'workspace_id' => $workspaceId,
                        'is_default' => false,
                    ]);
            }

            $schedule->fill([
                'workspace_id' => $workspaceId,
                'name' => $validated['name'],
                'next_list_content' => $validated['next_list_content'] ?? ShoppingListNextContent::Pending->value,
                'closes_automatically' => (bool) ($validated['closes_automatically'] ?? true),
                'waits_for_close' => (bool) ($validated['waits_for_close'] ?? false),
                'template_items' => $validated['template_items'] ?? [],
            ])->save();

            try {
                $recurrence = $saveRecurrence($schedule, $validated['recurrence']);
            } catch (ValidationException $exception) {
                $errors = [];

                foreach ($exception->errors() as $field => $messages) {
                    $errors["recurrence.{$field}"] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }

            if ($recurrence->next_run_at === null) {
                throw ValidationException::withMessages(['recurrence.ends_at' => [__('The schedule has no run left.')]]);
            }

            $this->moveOpenList($scheduler, $schedule, CarbonImmutable::instance($recurrence->next_run_at)->utc());
            $scheduler->ensureNextList($schedule->unsetRelation('recurrence'));

            return $schedule->unsetRelation('recurrence');
        });
    }

    /**
     * The open list whose deadline is still ahead follows a changed rule.
     */
    private function moveOpenList(ShoppingListScheduler $scheduler, ShoppingListSchedule $schedule, CarbonImmutable $nextRunAt): void
    {
        $open = $schedule->openList();

        if ($open === null || $open->due_at === null || $open->due_at->isPast() || $open->due_at->equalTo($nextRunAt)) {
            return;
        }

        if ($schedule->lists()->where('due_at', $nextRunAt)->exists()) {
            return;
        }

        $open->update([
            'due_at' => $nextRunAt,
            'name' => $scheduler->listName($schedule, $nextRunAt),
        ]);
    }
}
