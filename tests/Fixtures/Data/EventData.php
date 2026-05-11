<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Data;

use Carbon\CarbonImmutable;
use LaravelZod\Attributes\ZodSchema;
use LaravelZod\Tests\Fixtures\Enums\StatusEnum;
use Spatie\LaravelData\Data;

#[ZodSchema]
final class EventData extends Data
{
    /**
     * @param  UserData[]|null  $attendees
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?UserData $host,
        public ?array $attendees,
        public CarbonImmutable $starts_at,
        public StatusEnum $status,
    ) {}
}
