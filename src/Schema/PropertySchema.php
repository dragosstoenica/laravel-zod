<?php

declare(strict_types=1);

namespace LaravelZod\Schema;

final class PropertySchema
{
    /** @var Constraint[] */
    public array $constraints = [];

    /** @var array<int, string> Inline raw JS fragments to splice into the chain (after constraints). */
    public array $rawSuffixes = [];

    public bool $nullable = false;

    public bool $optional = false;

    public ?string $reference = null;

    public ?PropertySchema $arrayItem = null;

    /** @var list<int|string>|null */
    public ?array $enumValues = null;

    public bool $exclude = false;

    public bool $required = false;

    public bool $sawRequiredFlag = false;

    /**
     * When true, the FormRequestAnalyzer will NOT default the type to STRING.
     * Used by rules like `accepted`/`declined` that legitimately accept multiple
     * primitive types (string, number, boolean) — coercing to STRING would
     * reject valid input before the refine could check it.
     */
    public bool $preventDefaultType = false;

    /** When true, the renderer emits `z.lazy(() => XxxSchema)` instead of the bare reference. */
    public bool $useLazyReference = false;

    /** @var string[] Comments inserted before the property (for skipped server-only rules etc). */
    public array $comments = [];

    public function __construct(
        public readonly string $name,
        public PropertyType $type = PropertyType::ANY,
    ) {}

    public function addConstraint(Constraint $c): void
    {
        $this->constraints[] = $c;
    }

    public function hasConstraint(string $method): bool
    {
        foreach ($this->constraints as $c) {
            if ($c->method === $method) {
                return true;
            }
        }

        return false;
    }

    public function removeConstraint(string $method): void
    {
        $this->constraints = array_values(array_filter(
            $this->constraints,
            fn (Constraint $c): bool => $c->method !== $method,
        ));
    }
}
