<?php

declare(strict_types=1);

namespace LaravelZod\Translation;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Str;

final class MessageResolver
{
    /** @var array<string, string> */
    private array $custom = [];

    /** @var array<string, string> */
    private array $attributeNames = [];

    public function __construct(
        private readonly Translator $translator,
        private readonly string $locale,
    ) {}

    /**
     * @param  array<string, string>  $custom  FormRequest::messages() output
     * @param  array<string, string>  $attributeNames  FormRequest::attributes() output
     */
    public function setRequestContext(array $custom, array $attributeNames): void
    {
        $this->custom = $custom;
        $this->attributeNames = $attributeNames;
    }

    /**
     * @param  array<int|string, mixed>  $params  Placeholder values: ['max' => 160, 'other' => 'starts_at']
     */
    public function resolve(string $field, string $rule, array $params = [], ?string $typeHint = null): string
    {
        $template = $this->lookupTemplate($field, $rule, $typeHint);

        return $this->fillPlaceholders($template, $field, $params);
    }

    private function lookupTemplate(string $field, string $rule, ?string $typeHint): string
    {
        // 1. Exact override on FormRequest::messages(): "title.required"
        $exact = "{$field}.{$rule}";
        if (isset($this->custom[$exact])) {
            return $this->custom[$exact];
        }

        // 2. Wildcard: "*.required"
        $wildcard = "*.{$rule}";
        if (isset($this->custom[$wildcard])) {
            return $this->custom[$wildcard];
        }

        // 3. Laravel translation files. Try locale, then en.
        $key = "validation.{$rule}";
        $candidate = $this->translator->get($key, [], $this->locale);
        if ($candidate !== $key) {
            $resolved = $this->pickFromCandidate($candidate, $typeHint);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $candidate = $this->translator->get($key, [], 'en');
        if ($candidate !== $key) {
            $resolved = $this->pickFromCandidate($candidate, $typeHint);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 4. Fallback: humanise the rule.
        return Str::headline($rule).' validation failed.';
    }

    private function pickFromCandidate(mixed $candidate, ?string $typeHint): ?string
    {
        if (is_string($candidate)) {
            return $candidate;
        }

        if (! is_array($candidate)) {
            return null;
        }

        if ($typeHint !== null && isset($candidate[$typeHint]) && is_string($candidate[$typeHint])) {
            return $candidate[$typeHint];
        }

        // Fallback order when type hint missing or absent in array.
        foreach (['string', 'numeric', 'array', 'file'] as $sub) {
            if (isset($candidate[$sub]) && is_string($candidate[$sub])) {
                return $candidate[$sub];
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private function fillPlaceholders(string $template, string $field, array $params): string
    {
        $attribute = $this->attributeNames[$field] ?? str_replace('_', ' ', $field);

        $replacements = [':attribute' => $attribute];
        foreach ($params as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $replacements[':'.$key] = (string) $value;
            }
        }

        return strtr($template, $replacements);
    }
}
