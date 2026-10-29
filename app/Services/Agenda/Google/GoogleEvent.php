<?php

namespace App\Services\Agenda\Google;

use Carbon\CarbonImmutable;

/**
 * One event of a Google calendar as the agenda needs it. An all-day event
 * has its dates (`startDate` and the exclusive `endDate`, `Y-m-d`), a timed
 * one its instants.
 */
final readonly class GoogleEvent
{
    public function __construct(
        public string $id,
        public string $title,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public ?string $startDate,
        public ?string $endDate,
        public ?string $url,
    ) {}

    /**
     * The event from its `toArray()`.
     *
     * @param  array{id: string, title: string, starts_at: string|null, ends_at: string|null, start_date: string|null, end_date: string|null, url: string|null}  $event
     */
    public static function fromArray(array $event): self
    {
        return new self(
            id: $event['id'],
            title: $event['title'],
            startsAt: $event['starts_at'] === null ? null : CarbonImmutable::parse($event['starts_at'])->utc(),
            endsAt: $event['ends_at'] === null ? null : CarbonImmutable::parse($event['ends_at'])->utc(),
            startDate: $event['start_date'],
            endDate: $event['end_date'],
            url: $event['url'],
        );
    }

    public function isAllDay(): bool
    {
        return $this->startDate !== null;
    }

    /**
     * The event as scalars, for the cache.
     *
     * @return array{id: string, title: string, starts_at: string|null, ends_at: string|null, start_date: string|null, end_date: string|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->toIso8601ZuluString('microsecond'),
            'ends_at' => $this->endsAt?->toIso8601ZuluString('microsecond'),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'url' => $this->url,
        ];
    }
}
