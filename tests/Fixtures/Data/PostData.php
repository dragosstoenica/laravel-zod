<?php

declare(strict_types=1);

namespace LaravelZod\Tests\Fixtures\Data;

use LaravelZod\Attributes\ZodSchema;
use Spatie\LaravelData\Data;

#[ZodSchema]
final class PostData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public AuthorData $author,
    ) {}
}
