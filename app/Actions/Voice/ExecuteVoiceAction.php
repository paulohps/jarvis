<?php

namespace App\Actions\Voice;

use App\Actions\Reminders\CompleteReminderOccurrence;
use App\Actions\Reminders\CreateReminder;
use App\Actions\Shopping\AddShoppingListItem;
use App\Actions\Shopping\ToggleShoppingListItem;
use App\Models\Module;
use App\Models\ReminderOccurrence;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Models\WhatsappTarget;
use App\Services\Voice\VoiceAction;
use App\Services\Voice\VoiceConfirmations;
use App\Services\Voice\VoiceResolution;
use App\Services\Voice\VoiceResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Runs a voice action (US-10.2, US-10.3; Workflow 13), whichever
 * interpreter produced it: the app's on-device AI or the remote agent. It
 * has no rule of its own — it resolves the spoken names (the voice
 * resolvers) and calls the same Actions as the web and the API, whose
 * checks and validation apply as they are.
 *
 * - The action's module switched off for the user → `forbidden`.
 * - More than one candidate for a name → `needs_clarification`, nothing run.
 * - No candidate, or the Action refusing the input → `failed`.
 * - `whatsapp.send` → always `needs_confirmation`: nothing is sent until
 *   `ConfirmVoiceAction` runs with the token.
 *
 * Every message is translated, for the app to show and speak.
 */
final readonly class ExecuteVoiceAction
{
    public function __construct(
        private AddShoppingListItem $addShoppingListItem,
        private ToggleShoppingListItem $toggleShoppingListItem,
        private CreateReminder $createReminder,
        private CompleteReminderOccurrence $completeReminderOccurrence,
        private ResolveShoppingList $resolveShoppingList,
        private ResolveShoppingListItem $resolveShoppingListItem,
        private ResolveReminder $resolveReminder,
        private ResolveWhatsappTarget $resolveWhatsappTarget,
        private VoiceConfirmations $confirmations,
    ) {}

    public function __invoke(User $actor, VoiceAction $action): VoiceResult
    {
        if (! $actor->hasModule($action->module())) {
            return VoiceResult::forbidden($action, __('The :module module is switched off for you.', [
                'module' => __(Module::bySlug($action->module())->name),
            ]));
        }

        try {
            return match ($action->name) {
                VoiceAction::SHOPPING_ADD_ITEM => $this->addShoppingItem($actor, $action),
                VoiceAction::SHOPPING_CHECK_ITEM => $this->checkShoppingItem($actor, $action),
                VoiceAction::REMINDERS_CREATE => $this->createReminder($actor, $action),
                VoiceAction::REMINDERS_COMPLETE => $this->completeReminder($actor, $action),
                VoiceAction::WHATSAPP_SEND => $this->sendWhatsapp($actor, $action),
                default => throw new InvalidArgumentException("Unknown voice action [{$action->name}]."),
            };
        } catch (AuthorizationException) {
            return VoiceResult::forbidden($action, __('You are not allowed to do that.'));
        } catch (ValidationException $exception) {
            return VoiceResult::failed($action, (string) Arr::first(Arr::flatten($exception->errors())));
        }
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function addShoppingItem(User $actor, VoiceAction $action): VoiceResult
    {
        $resolution = ($this->resolveShoppingList)($actor, $action->parameter('list'));

        if (! $resolution->match instanceof ShoppingList) {
            return $this->unresolvedList($action, $resolution);
        }

        $item = ($this->addShoppingListItem)($actor, $resolution->match, [
            'name' => $action->parameter('item'),
            'quantity_note' => $action->parameter('quantity'),
        ]);

        return VoiceResult::done($action, __('Added ":item" to ":list".', [
            'item' => $item->name,
            'list' => $resolution->match->name,
        ]));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function checkShoppingItem(User $actor, VoiceAction $action): VoiceResult
    {
        $list = null;

        if ($action->parameter('list') !== null) {
            $listResolution = ($this->resolveShoppingList)($actor, $action->parameter('list'));

            if (! $listResolution->match instanceof ShoppingList) {
                return $this->unresolvedList($action, $listResolution);
            }

            $list = $listResolution->match;
        }

        $resolution = ($this->resolveShoppingListItem)($actor, (string) $action->parameter('item'), $list);

        if ($resolution->isAmbiguous()) {
            return $this->clarification($action, 'item', $resolution, __('Which item?'));
        }

        if (! $resolution->match instanceof ShoppingListItem) {
            return VoiceResult::failed($action, __('I could not find ":item" to check.', ['item' => $action->parameter('item')]));
        }

        $item = ($this->toggleShoppingListItem)($actor, $resolution->match, true);

        return VoiceResult::done($action, __('Checked ":item" on ":list".', [
            'item' => $item->name,
            'list' => $resolution->match->list->name,
        ]));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function createReminder(User $actor, VoiceAction $action): VoiceResult
    {
        $reminder = ($this->createReminder)($actor, [
            'title' => $action->parameter('title'),
            'note' => $action->parameter('note'),
            'due_at' => $action->parameter('due_at'),
        ]);

        $dueAt = $reminder->occurrences()->whereNull('completed_at')->first()?->due_at;

        if ($dueAt === null) {
            return VoiceResult::done($action, __('Reminder ":title" created.', ['title' => $reminder->title]));
        }

        return VoiceResult::done($action, __('Reminder ":title" created for :when.', [
            'title' => $reminder->title,
            // In the application's language, which Carbon follows.
            'when' => $dueAt->setTimezone($actor->timezone)->isoFormat('LLLL'),
        ]));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function completeReminder(User $actor, VoiceAction $action): VoiceResult
    {
        $resolution = ($this->resolveReminder)($actor, (string) $action->parameter('reminder'));

        if ($resolution->isAmbiguous()) {
            return $this->clarification($action, 'reminder', $resolution, __('Which reminder?'));
        }

        if (! $resolution->match instanceof ReminderOccurrence) {
            return VoiceResult::failed($action, __('I could not find an open reminder called ":reminder".', ['reminder' => $action->parameter('reminder')]));
        }

        ($this->completeReminderOccurrence)($actor, $resolution->match);

        return VoiceResult::done($action, __('Reminder ":title" completed.', ['title' => $resolution->match->reminder->title]));
    }

    /**
     * Never sends: the send waits for the user's confirmation.
     */
    private function sendWhatsapp(User $actor, VoiceAction $action): VoiceResult
    {
        $resolution = ($this->resolveWhatsappTarget)($actor, (string) $action->parameter('target'), $action->parameter('account'));

        if ($resolution->isAmbiguous()) {
            return $this->clarification($action, 'target', $resolution, __('Which contact or group?'));
        }

        if (! $resolution->match instanceof WhatsappTarget) {
            return VoiceResult::failed($action, __('I could not find a contact or group called ":target" in your WhatsApp accounts.', ['target' => $action->parameter('target')]));
        }

        $target = $resolution->match;
        $body = (string) $action->parameter('body');
        $confirmation = $this->confirmations->issue($actor, $target, $body);

        return VoiceResult::needsConfirmation(
            $action->with('target', $target->friendly_id),
            $confirmation['token'],
            $confirmation['expires_at'],
            [
                'account' => $target->account->nickname,
                'target' => $target->displayName(),
                'target_type' => $target->type->slug,
                'body' => $body,
            ],
            __('Send ":body" to :target? Confirm to send.', ['body' => $body, 'target' => $target->displayName()]),
        );
    }

    private function unresolvedList(VoiceAction $action, VoiceResolution $resolution): VoiceResult
    {
        if ($resolution->isAmbiguous()) {
            return $this->clarification($action, 'list', $resolution, __('Which list?'));
        }

        return VoiceResult::failed($action, $action->parameter('list') === null
            ? __('There is no active shopping list.')
            : __('I could not find a shopping list called ":list".', ['list' => $action->parameter('list')]));
    }

    /**
     * Asks which one, naming the options for the app to speak.
     */
    private function clarification(VoiceAction $action, string $parameter, VoiceResolution $resolution, string $question): VoiceResult
    {
        $labels = array_column($resolution->options, 'label');

        return VoiceResult::needsClarification(
            $action,
            $parameter,
            $resolution->options,
            $question.' '.Arr::join($labels, ', ', ' '.__('or').' ').'?',
        );
    }
}
