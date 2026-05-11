<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

final class SchemaRegistry
{
    /** @var array<string, string> */
    private array $byClass = [];

    public function register(string $fqn, string $exportName): void
    {
        $this->byClass[ltrim($fqn, '\\')] = $exportName;
    }

    public function lookup(string $fqn): ?string
    {
        return $this->byClass[ltrim($fqn, '\\')] ?? null;
    }

    public function has(string $fqn): bool
    {
        return isset($this->byClass[ltrim($fqn, '\\')]);
    }
}
