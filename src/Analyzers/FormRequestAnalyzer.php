<?php

declare(strict_types=1);

namespace LaravelZod\Analyzers;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use LaravelZod\Schema\Constraint;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;
use LaravelZod\Translation\MessageResolver;
use LaravelZod\Translation\RuleTranslator;
use ReflectionClass;

final readonly class FormRequestAnalyzer
{
    public function __construct(
        private RuleTranslator $translator,
        private MessageResolver $messages,
    ) {}

    /**
     * @param  ReflectionClass<object>  $class
     */
    public function analyze(ReflectionClass $class, string $exportName): ObjectSchema
    {
        $obj = new ObjectSchema($exportName, $class->getName());
        $obj->isInputSchema = true;

        $instance = $this->instantiate($class);

        $rules = method_exists($instance, 'rules') ? $instance->rules() : [];
        $custom = $this->stringMap($instance->messages());
        $attrs = $this->stringMap($instance->attributes());
        if (! is_array($rules)) {
            $rules = [];
        }
        /** @var array<int|string, mixed> $rules */
        $this->messages->setRequestContext($custom, $attrs);

        /** @var list<string> $fieldNames */
        $fieldNames = [];
        foreach (array_keys($rules) as $key) {
            $fieldNames[] = (string) $key;
        }

        foreach ($rules as $field => $rawRules) {
            $field = (string) $field;
            // Skip nested-array rules ("items.*.qty") for now — they need recursive object schemas.
            if (str_contains($field, '.')) {
                continue;
            }
            $prop = new PropertySchema($field);
            $obj->addProperty($prop);

            $parsed = $this->parseRules($rawRules);
            foreach ($parsed as [$name, $params]) {
                $this->translator->apply($prop, $name, $params, $obj, $fieldNames);
            }

            // After all rules applied, default type to STRING if it stayed ANY
            // and no rule explicitly opted out of that coercion.
            if ($prop->type === PropertyType::ANY && ! $prop->preventDefaultType) {
                $prop->type = PropertyType::STRING;
            }

            $this->finaliseRequiredAndOptional($prop);
        }

        return $obj;
    }

    /**
     * Required/optional has to be applied after the type is known. A field rules list like
     * ['required', 'numeric', 'between:0,90'] visits 'required' before the type is set, so we
     * defer the type-aware tail (string trim+min(1), array min(1), etc.) to here.
     */
    private function finaliseRequiredAndOptional(PropertySchema $prop): void
    {
        if ($prop->sawRequiredFlag) {
            $prop->optional = false;
            if ($prop->type === PropertyType::STRING && ! $prop->hasConstraint('min')) {
                $msg = $this->messages->resolve($prop->name, 'required');
                $prop->addConstraint(new Constraint('raw', ['.trim()']));
                $prop->addConstraint(new Constraint('min', [1], $msg));
            } elseif ($prop->type === PropertyType::ARRAY && ! $prop->hasConstraint('min')) {
                $msg = $this->messages->resolve($prop->name, 'required');
                $prop->addConstraint(new Constraint('min', [1], $msg));
            }

            return;
        }

        // No `required`, no `sometimes`: in Laravel, missing keys are valid by default unless
        // a presence rule forces them. Mirror that by marking the field optional.
        if (! $prop->optional) {
            $prop->optional = true;
        }
    }

    /**
     * @return list<array{0: string|object, 1: list<string>}>
     */
    private function parseRules(mixed $raw): array
    {
        $parsed = [];
        if (is_array($raw)) {
            $rules = $raw;
        } elseif (is_scalar($raw)) {
            $rules = explode('|', (string) $raw);
        } else {
            return $parsed;
        }

        foreach ($rules as $rule) {
            if (is_object($rule)) {
                $parsed[] = [$rule, []];

                continue;
            }
            $ruleString = is_scalar($rule) ? (string) $rule : '';
            if ($ruleString === '') {
                continue;
            }
            if (str_contains($ruleString, ':')) {
                [$name, $rest] = explode(':', $ruleString, 2);
                $params = $rest === '' ? [] : str_getcsv($rest, ',', '"', '\\');
                $parsed[] = [$name, array_map(strval(...), $params)];
            } else {
                $parsed[] = [$ruleString, []];
            }
        }

        return $parsed;
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function instantiate(ReflectionClass $class): FormRequest
    {
        $instance = $class->newInstanceWithoutConstructor();
        if (! $instance instanceof FormRequest) {
            throw new InvalidArgumentException("{$class->getName()} did not produce a FormRequest instance.");
        }
        // Boot Laravel's request lifecycle minimally so methods() that need it don't blow up.
        $instance->initialize([], [], [], [], [], [], null);

        return $instance;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @return array<string, string>
     */
    private function stringMap(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
