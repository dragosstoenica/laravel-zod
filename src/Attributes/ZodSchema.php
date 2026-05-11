<?php

declare(strict_types=1);

namespace LaravelZod\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ZodSchema
{
    public function __construct(
        public ?string $name = null,
    ) {}
}
