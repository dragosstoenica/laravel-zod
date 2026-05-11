<?php

declare(strict_types=1);

namespace LaravelZod\Analyzers;

use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeInterface;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;
use LaravelZod\Schema\SchemaRegistry;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final readonly class DataClassAnalyzer
{
    public function __construct(
        private SchemaRegistry $registry,
    ) {}

    /**
     * @param  ReflectionClass<object>  $class
     */
    public function analyze(ReflectionClass $class, string $exportName): ObjectSchema
    {
        $obj = new ObjectSchema($exportName, $class->getName());
        $obj->isInputSchema = false;

        $constructor = $class->getConstructor();
        if ($constructor === null) {
            return $obj;
        }

        foreach ($constructor->getParameters() as $param) {
            $obj->addProperty($this->analyzeParameter($param));
        }

        return $obj;
    }

    private function analyzeParameter(ReflectionParameter $param): PropertySchema
    {
        $prop = new PropertySchema($param->getName());
        $type = $param->getType();

        $this->applyType($prop, $type, $param);

        if ($param->isDefaultValueAvailable() && $param->getDefaultValue() === null) {
            $prop->nullable = true;
            $prop->optional = true;
        }

        return $prop;
    }

    private function applyType(PropertySchema $prop, mixed $type, ReflectionParameter $param): void
    {
        if ($type === null) {
            $prop->type = PropertyType::ANY;

            return;
        }

        if ($type instanceof ReflectionUnionType) {
            // Pick first non-null type from a union; mark nullable if null is part of it.
            foreach ($type->getTypes() as $sub) {
                if ($sub instanceof ReflectionNamedType && $sub->getName() === 'null') {
                    $prop->nullable = true;

                    continue;
                }
                $this->applyType($prop, $sub, $param);

                return;
            }

            return;
        }

        if (! $type instanceof ReflectionNamedType) {
            $prop->type = PropertyType::ANY;

            return;
        }

        if ($type->allowsNull()) {
            $prop->nullable = true;
        }

        $name = $type->getName();

        if ($type->isBuiltin()) {
            match ($name) {
                'string' => $prop->type = PropertyType::STRING,
                'int' => $prop->type = PropertyType::INTEGER,
                'float' => $prop->type = PropertyType::NUMBER,
                'bool' => $prop->type = PropertyType::BOOLEAN,
                'array' => $this->resolveArray($prop, $param),
                'mixed' => $prop->type = PropertyType::ANY,
                default => $prop->type = PropertyType::ANY,
            };

            return;
        }

        // Class-typed parameter.
        if (is_subclass_of($name, BackedEnum::class)) {
            $prop->type = PropertyType::ENUM;
            $reflection = new ReflectionEnum($name);
            /** @var list<int|string> $values */
            $values = [];
            foreach ($reflection->getCases() as $case) {
                $values[] = $case->getBackingValue();
            }
            $prop->enumValues = $values;

            return;
        }

        if (is_a($name, DateTimeInterface::class, allow_string: true)
            || is_a($name, CarbonInterface::class, allow_string: true)) {
            $prop->type = PropertyType::DATE;

            return;
        }

        if ($this->registry->has($name)) {
            $prop->type = PropertyType::REF;
            $prop->reference = $this->registry->lookup($name);

            return;
        }

        $prop->type = PropertyType::ANY;
    }

    private function resolveArray(PropertySchema $prop, ReflectionParameter $param): void
    {
        $prop->type = PropertyType::ARRAY;
        $itemType = $this->detectArrayItemType($param);
        if ($itemType === null) {
            return;
        }

        $itemProp = new PropertySchema($param->getName().'Item');

        if ($this->registry->has($itemType)) {
            $itemProp->type = PropertyType::REF;
            $itemProp->reference = $this->registry->lookup($itemType);
            $prop->arrayItem = $itemProp;

            return;
        }

        // Primitive array element types: string[], int[], float[], bool[]
        $primitive = match (mb_strtolower($itemType)) {
            'string' => PropertyType::STRING,
            'int', 'integer' => PropertyType::INTEGER,
            'float', 'double' => PropertyType::NUMBER,
            'bool', 'boolean' => PropertyType::BOOLEAN,
            default => null,
        };
        if ($primitive !== null) {
            $itemProp->type = $primitive;
            $prop->arrayItem = $itemProp;
        }
    }

    /**
     * Walks three sources looking for "@var ItemClass[]" or "@param ItemClass[] $name":
     *   1. Method-level PHPDoc on the constructor (block above __construct).
     *   2. Inline PHPDoc on the constructor-promoted parameter itself
     *
     *      (`/** @var Foo[] *​/ public ?array`).
     *   3. PHPDoc on the property declaration (rare for promoted props).
     */
    private function detectArrayItemType(ReflectionParameter $param): ?string
    {
        $context = $param->getDeclaringClass();

        // 1. Method-level "@param ItemClass[] $name"
        $methodDoc = $param->getDeclaringFunction()->getDocComment();
        if (is_string($methodDoc)
            && preg_match(
                '/@param\s+([A-Za-z0-9_\\\\]+)\[\][^\$]*\$'.preg_quote($param->getName(), '/').'\b/',
                $methodDoc,
                $m,
            ) === 1
        ) {
            return $this->resolveTypeName($m[1], $context);
        }

        // 2. Inline "/** @var ItemClass[] */ public ?array $name" — read the source.
        $inline = $this->readInlineParamDoc($param);
        if ($inline !== null
            && preg_match('/@var\s+([A-Za-z0-9_\\\\]+)\[\]/', $inline, $m) === 1
        ) {
            return $this->resolveTypeName($m[1], $context);
        }

        return null;
    }

    private function readInlineParamDoc(ReflectionParameter $param): ?string
    {
        $function = $param->getDeclaringFunction();
        $file = $function->getFileName();
        $startLine = $function->getStartLine();
        $endLine = $function->getEndLine();

        if ($file === false || $startLine === false || $endLine === false) {
            return null;
        }

        $source = @file($file, FILE_IGNORE_NEW_LINES);
        if ($source === false) {
            return null;
        }

        $constructorBody = implode("\n", array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        // Find a /** … */ block that's followed (within a few lines, possibly with attributes / modifiers)
        // by the parameter name.
        $name = preg_quote($param->getName(), '/');
        $pattern = '#/\*\*([^*]|\*(?!/))*\*/[^/]*?(?:public|protected|private|readonly|\?array|array)[^,]*?\$'.$name.'\b#s';
        if (preg_match($pattern, $constructorBody, $m) === 1) {
            return $m[0];
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>|null  $context
     */
    private function resolveTypeName(string $type, ?ReflectionClass $context): ?string
    {
        $primitive = mb_strtolower($type);
        if (in_array($primitive, ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean'], true)) {
            return $primitive;
        }

        if ($type[0] === '\\') {
            return ltrim($type, '\\');
        }

        if ($context instanceof ReflectionClass) {
            $candidate = $context->getNamespaceName().'\\'.$type;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return class_exists($type) ? $type : null;
    }
}
