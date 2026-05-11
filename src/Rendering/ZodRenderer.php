<?php

declare(strict_types=1);

namespace LaravelZod\Rendering;

use LaravelZod\Schema\Constraint;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;

final readonly class ZodRenderer
{
    /** @param string[] $headerLines */
    public function __construct(private array $headerLines = []) {}

    /**
     * @param  ObjectSchema[]  $schemas
     */
    public function render(array $schemas): string
    {
        $out = [];
        $out = $this->headerLines;
        if ($this->headerLines !== []) {
            $out[] = '';
        }
        $out[] = "import { z } from 'zod';";
        $out[] = '';

        foreach ($schemas as $schema) {
            $out[] = $this->renderSchema($schema);
            $out[] = '';
        }

        return mb_rtrim(implode("\n", $out))."\n";
    }

    private function renderSchema(ObjectSchema $schema): string
    {
        $lines = [];
        // Schemas that contain a z.lazy() back-edge need an explicit annotation,
        // otherwise TypeScript can't infer the type and emits an "implicitly
        // any" error under strict mode.
        $annotation = $this->hasLazyReference($schema) ? ': z.ZodTypeAny' : '';
        $lines[] = "export const {$schema->exportName}{$annotation} = z";
        $lines[] = '  .object({';

        foreach ($schema->properties as $prop) {
            if ($prop->exclude) {
                $lines[] = "    // {$prop->name}: excluded";

                continue;
            }
            foreach ($prop->comments as $comment) {
                $lines[] = "    // {$comment}";
            }
            $expr = $this->renderProperty($prop);
            $lines[] = "    {$prop->name}: {$expr},";
        }

        $lines[] = '  })';

        if ($schema->crossFieldRefines !== []) {
            // The `data` callback parameter is typed as `any` rather than the
            // inferred shape so cross-field rules can reference companion
            // fields (e.g. `password_confirmation` for `confirmed`) that exist
            // in the input but not in the schema's z.infer<> type.
            $lines[] = '  .superRefine((data: any, ctx) => {';
            foreach ($schema->crossFieldRefines as $refine) {
                $body = $this->indent($refine->body, '    ');
                $lines[] = $body;
            }
            $lines[] = '  })';
        }

        $lines[] = ';';
        $lines[] = "export type {$this->typeName($schema->exportName)} = z.infer<typeof {$schema->exportName}>;";

        return implode("\n", $lines);
    }

    private function hasLazyReference(ObjectSchema $schema): bool
    {
        foreach ($schema->properties as $prop) {
            if ($prop->useLazyReference) {
                return true;
            }
            if ($prop->arrayItem !== null && $prop->arrayItem->useLazyReference) {
                return true;
            }
        }

        return false;
    }

    private function renderProperty(PropertySchema $prop): string
    {
        $base = $this->baseExpression($prop);

        // Append all method-style constraints.
        foreach ($prop->constraints as $c) {
            $base .= $this->renderConstraint($c);
        }

        // Splice in raw chain fragments (e.g. .refine(...) blocks).
        foreach ($prop->rawSuffixes as $raw) {
            $base .= $raw;
        }

        // Nullable / optional last (Zod is order-sensitive for inference).
        if ($prop->nullable) {
            $base .= '.nullable()';
        }
        if ($prop->optional) {
            $base .= '.optional()';
        }

        return $base;
    }

    private function baseExpression(PropertySchema $prop): string
    {
        return match ($prop->type) {
            PropertyType::STRING => 'z.string()',
            PropertyType::INTEGER => 'z.number().int()',
            PropertyType::NUMBER => 'z.number()',
            PropertyType::BOOLEAN => 'z.boolean()',
            PropertyType::DATE => 'z.string()',
            PropertyType::ARRAY => $this->arrayExpression($prop),
            PropertyType::OBJECT => 'z.object({})',
            PropertyType::FILE => 'z.instanceof(File)',
            PropertyType::ENUM => $this->enumExpression($prop),
            PropertyType::REF => $this->refExpression($prop),
            PropertyType::ANY => 'z.unknown()',
        };
    }

    private function arrayExpression(PropertySchema $prop): string
    {
        if ($prop->arrayItem instanceof PropertySchema) {
            $itemExpr = $this->renderProperty($prop->arrayItem);

            return "z.array({$itemExpr})";
        }

        return 'z.array(z.unknown())';
    }

    private function refExpression(PropertySchema $prop): string
    {
        $name = $prop->reference ?? 'z.unknown()';

        return $prop->useLazyReference ? "z.lazy(() => {$name})" : $name;
    }

    private function enumExpression(PropertySchema $prop): string
    {
        $values = $prop->enumValues ?? [];
        $literal = json_encode($values);

        return "z.enum({$literal})";
    }

    private function renderConstraint(Constraint $c): string
    {
        if ($c->method === 'raw') {
            $first = $c->arguments[0] ?? '';

            return is_scalar($first) ? (string) $first : '';
        }

        $args = array_map($this->renderArgument(...), $c->arguments);

        if ($c->message !== null && $c->method !== 'regex') {
            $args[] = $this->jsString($c->message);
        }

        if ($c->method === 'regex' && $c->message !== null) {
            $regex = $args[0];

            return ".regex({$regex}, ".$this->jsString($c->message).')';
        }

        $argList = implode(', ', $args);

        return ".{$c->method}({$argList})";
    }

    private function renderArgument(mixed $arg): string
    {
        if (is_string($arg) && preg_match('~^/.+/[a-z]*$~', $arg) === 1) {
            return $arg;
        }
        if (is_int($arg) || is_float($arg)) {
            return (string) $arg;
        }
        if (is_string($arg)) {
            return $this->jsString($arg);
        }

        return 'undefined';
    }

    private function jsString(string $s): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s).'"';
    }

    private function indent(string $text, string $prefix): string
    {
        $lines = preg_split('/\r?\n/', $text) ?: [];

        return implode("\n", array_map(fn (string $l): string => $l === '' ? '' : $prefix.$l, $lines));
    }

    private function typeName(string $exportName): string
    {
        return $exportName.'Type';
    }
}
