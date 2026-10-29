<?php

namespace App\Services\Agenda;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A module that puts its schedule on the agenda's calendar.
 */
interface CalendarSource
{
    /**
     * The CalendarFeed::SOURCES keys its entries come under.
     *
     * @return list<string>
     */
    public function keys(): array;

    /**
     * Whether `$reader` may see it: they have its module.
     */
    public function isAvailableTo(User $reader): bool;

    /**
     * Its entries starting in [`$from`, `$to`), the runs still to come
     * projected. `$assignee` narrows the agenda's own items to the ones that
     * person is responsible for; the other sources have nobody responsible
     * and ignore it.
     *
     * @return list<CalendarEntry>
     */
    public function entries(User $reader, CarbonImmutable $from, CarbonImmutable $to, ?User $assignee): array;
}
