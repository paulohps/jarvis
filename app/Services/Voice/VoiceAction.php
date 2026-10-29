<?php

namespace App\Services\Voice;

use App\Models\Module;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The structured action of a voice command (US-10.2, US-10.3): what both
 * interpreters produce — the app's on-device AI (`POST /voice/actions`) and
 * the remote agent (`VoiceCommandAgent`) — and what `ExecuteVoiceAction`
 * runs. A name and its parameters, as spoken (`"dessa semana"`) or as the
 * friendly id of an option the user picked after a clarification:
 *
 * - `shopping.add_item`: `item`, optional `quantity` and `list`;
 * - `shopping.check_item`: `item`, optional `list`;
 * - `reminders.create`: `title`, optional `due_at` (a local date and time,
 *   `2026-10-06T09:00`, read in the user's timezone) and `note`;
 * - `reminders.complete`: `reminder`;
 * - `whatsapp.send`: `target` (a contact or group), `body`, optional
 *   `account` (the nickname of one of the user's accounts).
 */
final readonly class VoiceAction
{
    public const string SHOPPING_ADD_ITEM = 'shopping.add_item';

    public const string SHOPPING_CHECK_ITEM = 'shopping.check_item';

    public const string REMINDERS_CREATE = 'reminders.create';

    public const string REMINDERS_COMPLETE = 'reminders.complete';

    public const string WHATSAPP_SEND = 'whatsapp.send';

    /**
     * How the validation messages name each parameter (translated).
     *
     * @var array<string, string>
     */
    private const array ATTRIBUTES = [
        'item' => 'item',
        'quantity' => 'quantity or note',
        'list' => 'list',
        'title' => 'title',
        'due_at' => 'date and time',
        'note' => 'note',
        'reminder' => 'reminder',
        'target' => 'recipient',
        'body' => 'message',
        'account' => 'account',
    ];

    /**
     * @param  array<string, string|null>  $parameters
     */
    private function __construct(
        public string $name,
        public array $parameters,
    ) {}

    /**
     * Every action, in the order the tools are offered.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            self::SHOPPING_ADD_ITEM,
            self::SHOPPING_CHECK_ITEM,
            self::REMINDERS_CREATE,
            self::REMINDERS_COMPLETE,
            self::WHATSAPP_SEND,
        ];
    }

    /**
     * The validation rules of an action's parameters.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $name): array
    {
        return match ($name) {
            self::SHOPPING_ADD_ITEM => [
                'item' => ['required', 'string', 'max:255'],
                'quantity' => ['nullable', 'string', 'max:255'],
                'list' => ['nullable', 'string', 'max:255'],
            ],
            self::SHOPPING_CHECK_ITEM => [
                'item' => ['required', 'string', 'max:255'],
                'list' => ['nullable', 'string', 'max:255'],
            ],
            self::REMINDERS_CREATE => [
                'title' => ['required', 'string', 'max:255'],
                'due_at' => ['nullable', 'date'],
                'note' => ['nullable', 'string', 'max:5000'],
            ],
            self::REMINDERS_COMPLETE => [
                'reminder' => ['required', 'string', 'max:255'],
            ],
            self::WHATSAPP_SEND => [
                'target' => ['required', 'string', 'max:255'],
                'body' => ['required', 'string', 'max:4096'],
                'account' => ['nullable', 'string', 'max:255'],
            ],
            default => throw new InvalidArgumentException("Unknown voice action [{$name}]."),
        };
    }

    /**
     * A valid action: a known name and its parameters, trimmed, blank ones
     * as null, unknown ones dropped. The errors are keyed `action` and
     * `parameters.<name>`.
     *
     * @throws ValidationException
     */
    public static function from(mixed $name, mixed $parameters): self
    {
        Validator::make(['action' => $name], [
            'action' => ['required', 'string', Rule::in(self::names())],
        ], attributes: ['action' => __('action')])->validate();

        /** @var string $name */
        $rules = self::rules($name);
        $parameters = is_array($parameters) ? array_intersect_key($parameters, $rules) : [];

        foreach ($parameters as $key => $value) {
            if (is_string($value)) {
                $parameters[$key] = mb_trim($value) === '' ? null : mb_trim($value);
            }
        }

        $prefixed = [];

        foreach ($rules as $key => $keyRules) {
            $prefixed["parameters.{$key}"] = $keyRules;
        }

        Validator::make(['parameters' => $parameters], $prefixed, attributes: array_combine(
            array_keys($prefixed),
            array_map(fn (string $key): string => __(self::ATTRIBUTES[$key]), array_keys($rules)),
        ))->validate();

        $normalized = [];

        foreach (array_keys($rules) as $key) {
            $normalized[$key] = isset($parameters[$key]) ? (string) $parameters[$key] : null;
        }

        return new self($name, $normalized);
    }

    /**
     * The module the action belongs to.
     */
    public function module(): string
    {
        return match ($this->name) {
            self::SHOPPING_ADD_ITEM, self::SHOPPING_CHECK_ITEM => Module::SHOPPING,
            self::REMINDERS_CREATE, self::REMINDERS_COMPLETE => Module::REMINDERS,
            self::WHATSAPP_SEND => Module::WHATSAPP,
            default => throw new InvalidArgumentException("Unknown voice action [{$this->name}]."),
        };
    }

    public function parameter(string $key): ?string
    {
        return $this->parameters[$key] ?? null;
    }

    /**
     * The same action with a parameter replaced, e.g. by the friendly id of
     * the option the user picked.
     */
    public function with(string $key, ?string $value): self
    {
        return new self($this->name, [...$this->parameters, $key => $value]);
    }
}
