<?php

declare(strict_types=1);

namespace LaravelZod;

use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param  list<string>  $scan
     * @param  list<string>  $serverOnlyRules
     * @param  list<string>  $headerLines
     */
    public function __construct(
        public string $output,
        public array $scan,
        public ?string $locale,
        public string $suffix,
        public array $serverOnlyRules,
        public string $serverOnlyBehaviour,
        public bool $customRulesStrict,
        public array $headerLines,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $output = $raw['output'] ?? '';
        if (! is_string($output) || $output === '') {
            throw new InvalidArgumentException('laravel-zod.output must be a non-empty string.');
        }

        $scan = $raw['scan'] ?? [];
        if (! is_array($scan)) {
            throw new InvalidArgumentException('laravel-zod.scan must be an array of directory paths.');
        }
        $scan = array_values(array_filter($scan, is_string(...)));

        $locale = $raw['locale'] ?? null;
        if ($locale !== null && ! is_string($locale)) {
            throw new InvalidArgumentException('laravel-zod.locale must be a string or null.');
        }

        $suffix = $raw['suffix'] ?? 'Schema';
        if (! is_string($suffix)) {
            throw new InvalidArgumentException('laravel-zod.suffix must be a string.');
        }

        $serverOnlyRules = $raw['server_only_rules'] ?? [];
        if (! is_array($serverOnlyRules)) {
            throw new InvalidArgumentException('laravel-zod.server_only_rules must be an array.');
        }
        $serverOnlyRules = array_values(array_filter($serverOnlyRules, is_string(...)));

        $serverOnlyBehaviour = $raw['server_only_behaviour'] ?? 'comment';
        if (! is_string($serverOnlyBehaviour) || ! in_array($serverOnlyBehaviour, ['comment', 'fail'], true)) {
            throw new InvalidArgumentException("laravel-zod.server_only_behaviour must be 'comment' or 'fail'.");
        }

        $customRulesStrict = (bool) ($raw['custom_rules_strict'] ?? false);

        $headerLines = $raw['header'] ?? [];
        if (! is_array($headerLines)) {
            throw new InvalidArgumentException('laravel-zod.header must be an array of strings.');
        }
        $headerLines = array_values(array_filter($headerLines, is_string(...)));

        return new self(
            output: $output,
            scan: $scan,
            locale: $locale,
            suffix: $suffix,
            serverOnlyRules: $serverOnlyRules,
            serverOnlyBehaviour: $serverOnlyBehaviour,
            customRulesStrict: $customRulesStrict,
            headerLines: $headerLines,
        );
    }
}
