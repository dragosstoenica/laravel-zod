<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Data;

use LaravelZod\Attributes\ZodSchema;
use Spatie\LaravelData\Data;

#[ZodSchema]
final class CommentData extends Data
{
    /**
     * @param  CommentData[]|null  $replies
     */
    public function __construct(
        public int $id,
        public string $body,
        public ?self $parent,
        public ?array $replies,
    ) {}
}
