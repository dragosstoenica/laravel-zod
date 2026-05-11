<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

/**
 * A `.superRefine()` block emitted at the parent ObjectSchema level.
 * `$body` is the JS source for the validation, with `data` and `ctx` available.
 */
final readonly class CrossFieldRefine
{
    public function __construct(
        public string $field,
        public string $body,
    ) {}
}
