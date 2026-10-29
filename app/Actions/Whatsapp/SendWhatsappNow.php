<?php

namespace App\Actions\Whatsapp;

use App\Models\User;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappDeliverySource;
use App\Services\Whatsapp\WhatsappTemplateSchema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Send now" (US-7.5): sends a message right away through the WhatsApp
 * service, without scheduling anything — no `whatsapp_messages` row, one
 * delivery per target, triggered by the user: `manual`, or `voice` for a
 * confirmed voice command (US-10.3). Typed numbers become
 * unsynced targets, as when scheduling.
 *
 * The input is the one of WhatsappMessageFields; the body is written out
 * for each target. With a `poll` (`question`, `options`, `multiple`, as
 * SendWhatsappChatContent::pollOf() takes them; errors under `poll.*`)
 * that poll goes to every target instead. Only users with the `whatsapp` module, from their own
 * `open` accounts.
 */
final readonly class SendWhatsappNow
{
    use AuthorizesWhatsappModule;

    public function __construct(
        private SendWhatsAppMessage $send,
        private WhatsappTemplateSchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  string  $source  `WhatsappDeliverySource::MANUAL` or `::VOICE`
     * @return Collection<int, WhatsappDelivery>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $actor, array $input, string $source = WhatsappDeliverySource::MANUAL): Collection
    {
        $this->authorizeWhatsapp($actor);

        $poll = $this->poll($input);
        $fields = WhatsappMessageFields::validate($actor, [...$input, 'sends_poll' => $poll !== null]);
        $targets = DB::transaction(fn (): Collection => $fields->targets());
        $deliveries = new Collection;

        foreach ($targets as $target) {
            $deliveries->push(($this->send)(
                $fields->account,
                $target,
                $poll !== null ? $poll['name'] : $this->schema->render($fields->body, $target, $actor),
                $source,
                triggeredBy: $actor,
                payload: $poll !== null ? ['poll' => $poll] : null,
            ));
        }

        return $deliveries;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, options: list<string>, selectable_count: int}|null
     *
     * @throws ValidationException
     */
    private function poll(array $input): ?array
    {
        $poll = $input['poll'] ?? null;

        if ($poll === null || $poll === []) {
            return null;
        }

        try {
            return SendWhatsappChatContent::pollOf(
                is_array($poll) ? ($poll['question'] ?? null) : null,
                is_array($poll) && is_array($poll['options'] ?? null) ? $poll['options'] : [],
                is_array($poll) && filter_var($poll['multiple'] ?? false, FILTER_VALIDATE_BOOLEAN),
            );
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $field => $messages) {
                $errors["poll.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
