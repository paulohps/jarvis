<?php

namespace App\Ai\Tools;

use App\Services\Voice\VoiceAction;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * One tool of VoiceCommandAgent per VoiceAction (US-10.2): the model calls
 * it with the action's parameters, which are validated like the ones of
 * `POST /voice/actions` (a refusal goes back to the model to correct) and
 * handed to the agent. The tool runs nothing: ExecuteVoiceAction does,
 * after the agent answers, the same as for the app's own interpreter.
 */
final readonly class VoiceActionTool implements Tool
{
    /**
     * @param  Closure(VoiceAction): string  $record  keeps the action and returns what the model is told
     */
    public function __construct(
        public string $action,
        private Closure $record,
    ) {}

    /**
     * The tool's name: the action's, with an underscore for the dot that
     * providers do not accept (`shopping_add_item`).
     */
    public function name(): string
    {
        return str_replace('.', '_', $this->action);
    }

    public function description(): string
    {
        return match ($this->action) {
            VoiceAction::SHOPPING_ADD_ITEM => 'Add an item to a shopping list of the household.',
            VoiceAction::SHOPPING_CHECK_ITEM => 'Check (mark as picked up) an item of a shopping list.',
            VoiceAction::REMINDERS_CREATE => 'Create a reminder or task, optionally at a date and time.',
            VoiceAction::REMINDERS_COMPLETE => 'Mark a reminder or task as done.',
            VoiceAction::WHATSAPP_SEND => 'Send a WhatsApp message to a contact or group of the user. The user confirms it before it is sent.',
            default => throw new InvalidArgumentException("Unknown voice action [{$this->action}]."),
        };
    }

    public function handle(Request $request): string
    {
        return ($this->record)(VoiceAction::from($this->action, $request->all()));
    }

    public function schema(JsonSchema $schema): array
    {
        return match ($this->action) {
            VoiceAction::SHOPPING_ADD_ITEM => [
                'item' => $schema->string()->description('The item to add, as said (e.g. "leite").')->required(),
                'quantity' => $schema->string()->description('The quantity or a note, if said (e.g. "2 litros").'),
                'list' => $schema->string()->description('The words naming the list, as said (e.g. "dessa semana"); omit when no list was named. "A lista de compras" or "the shopping list" names no list: omit it.'),
            ],
            VoiceAction::SHOPPING_CHECK_ITEM => [
                'item' => $schema->string()->description('The item to check, as said.')->required(),
                'list' => $schema->string()->description('The words naming the list, as said; omit when no list was named. "A lista de compras" or "the shopping list" names no list: omit it.'),
            ],
            VoiceAction::REMINDERS_CREATE => [
                'title' => $schema->string()->description('What to be reminded of.')->required(),
                'due_at' => $schema->string()->description('When, as a local date and time YYYY-MM-DDTHH:MM in the user\'s timezone, without offset; omit when no time was said.'),
                'note' => $schema->string()->description('Any extra detail, if said.'),
            ],
            VoiceAction::REMINDERS_COMPLETE => [
                'reminder' => $schema->string()->description('The words naming the reminder, as said.')->required(),
            ],
            VoiceAction::WHATSAPP_SEND => [
                'target' => $schema->string()->description('The contact or group, as said (e.g. "Ana", "grupo da família").')->required(),
                'body' => $schema->string()->description('The message to send, as the user wants it written.')->required(),
                'account' => $schema->string()->description('The nickname of the user\'s WhatsApp account to send from, only if said.'),
            ],
            default => throw new InvalidArgumentException("Unknown voice action [{$this->action}]."),
        };
    }
}
