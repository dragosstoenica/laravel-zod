<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

final class ObjectSchema
{
    /** @var PropertySchema[] */
    public array $properties = [];

    /** @var CrossFieldRefine[] */
    public array $crossFieldRefines = [];

    public bool $isInputSchema = false;

    public function __construct(
        public readonly string $exportName,
        public readonly string $sourceClass,
    ) {}

    public function addProperty(PropertySchema $p): void
    {
        $this->properties[] = $p;
    }

    public function addRefine(CrossFieldRefine $r): void
    {
        $this->crossFieldRefines[] = $r;
    }
}
