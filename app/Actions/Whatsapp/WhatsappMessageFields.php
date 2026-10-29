<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappTarget;
use App\Rules\ValidTemplate;
use App\Services\Whatsapp\WhatsappTemplateSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

/**
 * What a WhatsApp message is sent from, to and with (US-7.3, US-7.5), shared
 * by ScheduleWhatsappMessage, UpdateWhatsappMessage and SendWhatsappNow:
 *
 * - `account`: the friendly id of one of the user's own accounts, `open`;
 * - `targets`: friendly ids of that account's contacts and groups, and/or
 *   `numbers` typed in E.164 (`+5511999990000`; spaces, dashes, dots and
 *   parentheses are dropped) — at least one in all, up to MAX_TARGETS;
 * - `body`: a template of WhatsappTemplateSchema, up to MAX_BODY characters;
 *   optional when `has_media` (the message sends a file, the body as its
 *   caption), `writes_with_ai` (the AI writes it when it goes out) or
 *   `sends_poll` (a poll goes instead).
 *
 * `validate()` only reads; `targets()` of the result creates the typed
 * numbers that are new as unsynced targets (`is_synced` false).
 */
final readonly class WhatsappMessageFields
{
    public const int MAX_TARGETS = 50;

    public const int MAX_BODY = 4096;

    public const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    /**
     * @param  Collection<int, WhatsappTarget>  $selectedTargets
     * @param  list<string>  $numbers
     */
    private function __construct(
        public WhatsappAccount $account,
        public Collection $selectedTargets,
        public array $numbers,
        public string $body,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(User $actor, array $input): self
    {
        $input = self::normalize($input);
        $account = null;
        $targets = new Collection;

        /** @var array{account: string, targets?: list<string>, numbers?: list<string>, body?: string|null} $validated */
        $validated = Validator::make($input, [
            'account' => ['required', 'string'],
            'targets' => ['nullable', 'array', 'max:'.self::MAX_TARGETS],
            'targets.*' => ['string', 'distinct'],
            'numbers' => ['nullable', 'array', 'max:'.self::MAX_TARGETS],
            'numbers.*' => ['string', 'distinct', 'regex:'.self::E164_PATTERN],
            'body' => [($input['has_media'] ?? false) === true || ($input['writes_with_ai'] ?? false) === true || ($input['sends_poll'] ?? false) === true ? 'nullable' : 'required', 'string', 'max:'.self::MAX_BODY, new ValidTemplate(new WhatsappTemplateSchema)],
        ], [
            'numbers.*.regex' => __('Type the number in international format, such as +5511999990000.'),
        ], [
            'account' => __('account'),
            'targets' => __('recipients'),
            'numbers' => __('numbers'),
            'body' => __('message'),
        ])->after(function (ValidationValidator $validator) use ($actor, $input, &$account, &$targets): void {
            if ($validator->errors()->hasAny(['account', 'targets', 'targets.*', 'numbers', 'numbers.*'])) {
                return;
            }

            $account = $actor->whatsappAccounts()->with('status')->where('friendly_id', $input['account'])->first();

            if ($account === null) {
                $validator->errors()->add('account', __('Choose one of your WhatsApp accounts.'));

                return;
            }

            if (! $account->isOpen()) {
                $validator->errors()->add('account', __('The WhatsApp account :nickname is not connected. Reconnect it first.', ['nickname' => $account->nickname]));

                return;
            }

            /** @var list<string> $targetIds */
            $targetIds = $input['targets'] ?? [];
            /** @var list<string> $numbers */
            $numbers = $input['numbers'] ?? [];

            if ($targetIds === [] && $numbers === []) {
                $validator->errors()->add('targets', __('Choose at least one contact or group, or type a number.'));

                return;
            }

            if (count($targetIds) + count($numbers) > self::MAX_TARGETS) {
                $validator->errors()->add('targets', __('Send to at most :max recipients.', ['max' => self::MAX_TARGETS]));

                return;
            }

            $targets = $account->targets()->whereIn('friendly_id', $targetIds)->get();

            if ($targets->count() !== count($targetIds)) {
                $validator->errors()->add('targets', __('Choose contacts and groups of the account :nickname.', ['nickname' => $account->nickname]));
            }
        })->validate();

        /** @var WhatsappAccount $account */
        return new self($account, $targets, $validated['numbers'] ?? [], $validated['body'] ?? '');
    }

    /**
     * Every target: the ones chosen, then the typed numbers (created when
     * new), each once.
     *
     * @return Collection<int, WhatsappTarget>
     */
    public function targets(): Collection
    {
        $targets = $this->selectedTargets->toBase()->concat(array_map(
            fn (string $number): WhatsappTarget => WhatsappTarget::forNumber($this->account, $number),
            $this->numbers,
        ));

        return new Collection($targets->unique(fn (WhatsappTarget $target): int => $target->id)->values()->all());
    }

    /**
     * Trims the text fields and writes the typed numbers in plain E.164.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalize(array $input): array
    {
        if (is_string($input['account'] ?? null)) {
            $input['account'] = trim($input['account']);
        }

        if (is_array($input['numbers'] ?? null)) {
            $input['numbers'] = array_values(array_filter(array_map(
                static fn (mixed $number): mixed => is_string($number) ? (string) preg_replace('/[\s\-().]/', '', $number) : $number,
                $input['numbers'],
            ), static fn (mixed $number): bool => $number !== ''));
        }

        return $input;
    }
}
