<?php

namespace App\Services\Agenda\Sources;

use App\Models\Module;
use App\Models\User;
use App\Models\WhatsappDelivery;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Agenda\CalendarEntry;
use App\Services\Agenda\CalendarFeed;
use App\Services\Agenda\CalendarSource;
use App\Services\Agenda\ProjectsRuns;
use App\Services\Recurrences\RecurrenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The reader's own scheduled WhatsApp messages on the agenda: each slot
 * already sent (its deliveries, one per target, as one entry) and the
 * coming runs of the messages still active — not cancelled nor paused.
 */
final readonly class WhatsappCalendarSource implements CalendarSource
{
    use ProjectsRuns;

    public function __construct(private RecurrenceCalculator $calculator) {}

    public function keys(): array
    {
        return [CalendarFeed::WHATSAPP];
    }

    public function isAvailableTo(User $reader): bool
    {
        return $reader->hasModule(Module::WHATSAPP);
    }

    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array
    {
        $entries = WhatsappDelivery::query()
            ->whereNotNull('whatsapp_message_id')
            ->where('scheduled_for', '>=', $from)
            ->where('scheduled_for', '<', $to)
            ->whereHas('message', fn (Builder $query) => $query->withTrashed()->where('user_id', $reader->id))
            ->with(['message' => fn ($query) => $query->withTrashed(), 'target'])
            ->get()
            ->groupBy(fn (WhatsappDelivery $delivery): string => "{$delivery->whatsapp_message_id}@{$delivery->scheduled_for?->getTimestamp()}")
            ->map(function (Collection $deliveries): CalendarEntry {
                /** @var WhatsappDelivery $first */
                $first = $deliveries->first();

                return new CalendarEntry(
                    key: "{$first->message->friendly_id}@{$first->scheduled_for?->getTimestamp()}",
                    source: CalendarFeed::WHATSAPP,
                    title: $this->title($deliveries->map(fn (WhatsappDelivery $delivery): WhatsappTarget => $delivery->target)),
                    startsAt: CarbonImmutable::instance($first->scheduled_for),
                    subtitle: Str::limit($first->body, 80),
                    url: route('whatsapp.history.index'),
                );
            })
            ->values()
            ->all();

        $messages = WhatsappMessage::query()
            ->where('user_id', $reader->id)
            ->whereNull('cancelled_at')
            ->whereHas('recurrence', fn (Builder $query) => $query->where('is_paused', false)->whereNotNull('next_run_at'))
            ->with(['recurrence.frequency', 'recurrence.weekdays', 'targets'])
            ->get();

        foreach ($messages as $message) {
            foreach ($this->runsBetween($this->calculator, $message->recurrence, $from, $to) as $run) {
                $entries[] = new CalendarEntry(
                    key: "{$message->friendly_id}@{$run->getTimestamp()}",
                    source: CalendarFeed::WHATSAPP,
                    title: $this->title($message->targets),
                    startsAt: $run,
                    subtitle: Str::limit($message->body, 80),
                    url: route('whatsapp.messages.edit', $message),
                    isProjected: true,
                );
            }
        }

        return array_values($entries);
    }

    /**
     * "WhatsApp to Ana, Family".
     *
     * @param  Collection<int, WhatsappTarget>  $targets
     */
    private function title(Collection $targets): string
    {
        return __('WhatsApp to :targets', [
            'targets' => $targets->map(fn (WhatsappTarget $target): string => $target->displayName())->unique()->join(', '),
        ]);
    }
}
