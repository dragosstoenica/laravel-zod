<?php

declare(strict_types=1);

namespace LaravelZod\Contracts;

interface HasZodSchema
{
    /**
     * Return a Zod 4 expression string. Will be inlined verbatim into the field's chain.
     * Examples:
     *   "z.string().regex(/^[A-Z]{3}$/)"
     *   ".refine((v) => v.startsWith('+'), 'Must start with +')"  (chain fragment)
     */
    public function toZod(): string;
}
