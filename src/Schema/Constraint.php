<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

/**
 * One link in a Zod chain. Examples:
 *   new Constraint('min', [1], 'Title is required.')   →  .min(1, "Title is required.")
 *   new Constraint('regex', ['/^[A-Z]+$/'])            →  .regex(/^[A-Z]+$/)
 *   new Constraint('raw', ['.email()'])                →  .email()
 */
final readonly class Constraint
{
    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __construct(
        public string $method,
        public array $arguments = [],
        public ?string $message = null,
    ) {}

    public static function raw(string $expression): self
    {
        return new self('raw', [$expression]);
    }
}
