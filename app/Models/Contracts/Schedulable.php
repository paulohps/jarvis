<?php

namespace App\Models\Contracts;

use Carbon\CarbonImmutable;

/**
 * An owner of a `recurrences` row (invoice recurrence, WhatsApp message,
 * reminder). The scheduler finds its due recurrences and calls
 * `runScheduledSlot()` once per slot; the owner does the module's work.
 */
interface Schedulable
{
    /**
     * Run the work of one occurrence. `$slot` is the UTC instant the
     * occurrence was due, used as its idempotency key.
     */
    public function runScheduledSlot(CarbonImmutable $slot): void;

    /**
     * The PHP timezone identifier the recurrence is computed in: the one of
     * the person who owns the rule.
     */
    public function ownerTimezone(): string;

    /**
     * The queue `RunScheduledSlot` runs on for this owner's module.
     */
    public function scheduledSlotQueue(): string;
}
