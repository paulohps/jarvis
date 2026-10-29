<?php

namespace App\Services\Agenda;

use App\Models\User;
use App\Services\Agenda\Sources\GoogleCalendarSource;
use App\Services\Agenda\Sources\InvoiceCalendarSource;
use App\Services\Agenda\Sources\ReminderCalendarSource;
use App\Services\Agenda\Sources\ShoppingCalendarSource;
use App\Services\Agenda\Sources\WhatsappCalendarSource;
use Carbon\CarbonImmutable;

/**
 * Everything scheduled in the system on one calendar: the agenda's own
 * reminders and events, the sending of invoices, the scheduled WhatsApp
 * messages, the recurring shopping lists and the reader's own Google
 * calendars — each source only for whoever has its module. The web agenda
 * and the API read it from here.
 */
final readonly class CalendarFeed
{
    public const string REMINDERS = 'reminders';

    public const string EVENTS = 'events';

    public const string INVOICES = 'invoices';

    public const string WHATSAPP = 'whatsapp';

    public const string SHOPPING = 'shopping';

    public const string GOOGLE = 'google';

    /**
     * Every source key, in display order.
     */
    public const array SOURCES = [self::REMINDERS, self::EVENTS, self::INVOICES, self::WHATSAPP, self::SHOPPING, self::GOOGLE];

    /**
     * The longest window read at once, in days (a month view shows 42).
     */
    public const int MAX_DAYS = 62;

    /**
     * @var list<CalendarSource>
     */
    private array $sources;

    public function __construct(ReminderCalendarSource $reminders, InvoiceCalendarSource $invoices, WhatsappCalendarSource $whatsapp, ShoppingCalendarSource $shopping, GoogleCalendarSource $google)
    {
        $this->sources = [$reminders, $invoices, $whatsapp, $shopping, $google];
    }

    /**
     * The source keys `$reader` may see, in display order.
     *
     * @return list<string>
     */
    public function keysFor(User $reader): array
    {
        $keys = [];

        foreach ($this->sources as $source) {
            if ($source->isAvailableTo($reader)) {
                array_push($keys, ...$source->keys());
            }
        }

        return $keys;
    }

    /**
     * The entries starting in [`$from`, `$to`) by start — the all-day ones
     * of a day first — leaving out the `$hidden` source keys.
     *
     * @param  list<string>  $hidden
     * @return list<CalendarEntry>
     */
    public function between(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee = null, array $hidden = []): array
    {
        $entries = [];

        foreach ($this->sources as $source) {
            if (! $source->isAvailableTo($reader) || array_diff($source->keys(), $hidden) === []) {
                continue;
            }

            foreach ($source->entries($reader, $from, $to, $assignee) as $entry) {
                if (! in_array($entry->source, $hidden, true)) {
                    $entries[] = $entry;
                }
            }
        }

        $timezone = $reader->timezone;

        usort($entries, fn (CalendarEntry $a, CalendarEntry $b): int => [$a->day($timezone), $a->isAllDay ? 0 : 1, $a->startsAt->getTimestamp(), $a->title]
            <=> [$b->day($timezone), $b->isAllDay ? 0 : 1, $b->startsAt->getTimestamp(), $b->title]);

        return $entries;
    }
}
