<?php

declare(strict_types=1);

namespace LaravelZod\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Foundation\Http\FormRequest;
use LaravelZod\Analyzers\DataClassAnalyzer;
use LaravelZod\Analyzers\FormRequestAnalyzer;
use LaravelZod\Attributes\ZodSchema;
use LaravelZod\Config;
use LaravelZod\Discovery\ClassDiscoverer;
use LaravelZod\Rendering\ZodRenderer;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\SchemaRegistry;
use LaravelZod\Translation\MessageResolver;
use LaravelZod\Translation\RuleTranslator;
use ReflectionClass;

final class GenerateZodSchemasCommand extends Command
{
    protected $signature = 'zod:generate
                            {--locale= : Locale used for default validation messages (defaults to app locale, then en).}
                            {--dry-run : Print to stdout instead of writing the file.}';

    protected $description = 'Generate Zod schemas from #[ZodSchema]-attributed Spatie Data classes (output) and Laravel FormRequests (input).';

    public function handle(Translator $translator, ClassDiscoverer $discoverer): int
    {
        $raw = config('laravel-zod');
        if (! is_array($raw)) {
            $this->error('laravel-zod config is missing or invalid. Run `php artisan vendor:publish --tag=laravel-zod-config`.');

            return self::FAILURE;
        }
        /** @var array<string, mixed> $normalised */
        $normalised = [];
        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $normalised[$k] = $v;
            }
        }
        $cfg = Config::fromArray($normalised);

        $localeOption = $this->option('locale');
        $localeOption = is_string($localeOption) && $localeOption !== '' ? $localeOption : null;
        $locale = $localeOption ?? $cfg->locale ?? (string) app()->getLocale();

        $messages = new MessageResolver($translator, $locale);
        $ruleTranslator = new RuleTranslator($messages);
        $ruleTranslator->serverOnlyRules = $cfg->serverOnlyRules;
        $ruleTranslator->serverOnlyBehaviour = $cfg->serverOnlyBehaviour;
        $ruleTranslator->customRulesStrict = $cfg->customRulesStrict;

        $registry = new SchemaRegistry;
        $dataAnalyzer = new DataClassAnalyzer($registry);
        $requestAnalyzer = new FormRequestAnalyzer($ruleTranslator, $messages);

        // ── Pass 1: discover all classes and register their schema names ─────
        $classes = $discoverer->discover($cfg->scan);
        if ($classes === []) {
            $this->warn('No classes with #[ZodSchema] found.');

            return self::SUCCESS;
        }

        /** @var list<array{0: ReflectionClass<object>, 1: string}> $named */
        $named = [];
        foreach ($classes as $reflection) {
            $name = $this->schemaName($reflection, $cfg->suffix);
            $registry->register($reflection->getName(), $name);
            $named[] = [$reflection, $name];
        }

        // ── Pass 2: analyze every class, building ObjectSchemas ──────────────
        /** @var list<ObjectSchema> $schemas */
        $schemas = [];
        foreach ($named as [$reflection, $name]) {
            $schema = is_subclass_of($reflection->getName(), FormRequest::class)
                ? $requestAnalyzer->analyze($reflection, $name)
                : $dataAnalyzer->analyze($reflection, $name);
            $schemas[] = $schema;
            $this->info(($schema->isInputSchema ? '[input]  ' : '[output] ').$name.'  ←  '.$reflection->getShortName());
        }

        $schemas = $this->topologicalSort($schemas);

        // ── Render ──────────────────────────────────────────────────────────
        $renderer = new ZodRenderer($cfg->headerLines);
        $rendered = $renderer->render($schemas);

        if ($this->option('dry-run') === true) {
            $this->line($rendered);

            return self::SUCCESS;
        }

        $dir = dirname($cfg->output);
        if (! is_dir($dir)) {
            mkdir($dir, recursive: true);
        }
        file_put_contents($cfg->output, $rendered);

        $this->newLine();
        $this->info('✓ Wrote '.count($schemas).' schemas to '.$cfg->output);

        if ($ruleTranslator->warnings !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($ruleTranslator->warnings as $w) {
                $this->line('  - '.$w);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Order schemas so any referenced schema is declared before its dependant.
     * `export const A = …` is not hoisted — referencing B before B's `export const`
     * triggers a TDZ error at runtime.
     *
     * For circular dependencies (A → B → A, or A → A), one or more references
     * become "back-edges" — refs to a schema that is itself currently being
     * resolved. We detect those via DFS coloring (white/gray/black) and mark
     * the offending property's `useLazyReference = true`. The renderer then
     * wraps the ref in `z.lazy(() => Schema)` which defers evaluation until
     * after both schemas have been declared.
     *
     * @param  list<ObjectSchema>  $schemas
     * @return list<ObjectSchema>
     */
    private function topologicalSort(array $schemas): array
    {
        /** @var array<string, ObjectSchema> $byName */
        $byName = [];
        foreach ($schemas as $s) {
            $byName[$s->exportName] = $s;
        }

        /** @var array<string, list<string>> $deps */
        $deps = [];
        foreach ($schemas as $s) {
            $deps[$s->exportName] = $this->collectDependencies($s, $byName);
        }

        /** @var array<string, 'white'|'gray'|'black'> $color */
        $color = [];
        foreach (array_keys($byName) as $name) {
            $color[$name] = 'white';
        }

        /** @var list<ObjectSchema> $sorted */
        $sorted = [];
        /** @var array<string, array<string, true>> $backEdges */
        $backEdges = [];

        $visit = function (string $name) use (&$visit, &$sorted, &$color, &$backEdges, &$deps, $byName): void {
            if ($color[$name] !== 'white') {
                return;
            }

            $color[$name] = 'gray';
            foreach ($deps[$name] ?? [] as $dep) {
                if (! isset($color[$dep])) {
                    continue;
                }
                if ($color[$dep] === 'gray') {
                    // Back-edge: $name → $dep is a cycle. Defer the ref via z.lazy().
                    $backEdges[$name][$dep] = true;

                    continue;
                }
                if ($color[$dep] === 'white') {
                    $visit($dep);
                }
            }
            $color[$name] = 'black';
            if (isset($byName[$name])) {
                $sorted[] = $byName[$name];
            }
        };

        foreach (array_keys($byName) as $name) {
            if ($color[$name] === 'white') {
                $visit($name);
            }
        }

        $this->markLazyReferences($sorted, $backEdges);

        return $sorted;
    }

    /**
     * @param  list<ObjectSchema>  $sorted
     * @param  array<string, array<string, true>>  $backEdges
     */
    private function markLazyReferences(array $sorted, array $backEdges): void
    {
        if ($backEdges === []) {
            return;
        }

        foreach ($sorted as $schema) {
            $cycleTargets = $backEdges[$schema->exportName] ?? [];
            if ($cycleTargets === []) {
                continue;
            }
            foreach ($schema->properties as $prop) {
                if ($prop->reference !== null && isset($cycleTargets[$prop->reference])) {
                    $prop->useLazyReference = true;
                }
                if ($prop->arrayItem !== null
                    && $prop->arrayItem->reference !== null
                    && isset($cycleTargets[$prop->arrayItem->reference])) {
                    $prop->arrayItem->useLazyReference = true;
                }
            }
        }
    }

    /**
     * @param  array<string, ObjectSchema>  $byName
     * @return list<string>
     */
    private function collectDependencies(ObjectSchema $schema, array $byName): array
    {
        $deps = [];
        foreach ($schema->properties as $prop) {
            if ($prop->reference !== null && isset($byName[$prop->reference])) {
                $deps[] = $prop->reference;
            }
            if ($prop->arrayItem !== null && $prop->arrayItem->reference !== null && isset($byName[$prop->arrayItem->reference])) {
                $deps[] = $prop->arrayItem->reference;
            }
        }

        return array_values(array_unique($deps));
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function schemaName(ReflectionClass $class, string $suffix): string
    {
        foreach ($class->getAttributes(ZodSchema::class) as $attr) {
            $instance = $attr->newInstance();
            if ($instance->name !== null) {
                return $instance->name;
            }
        }

        return $class->getShortName().$suffix;
    }
}
