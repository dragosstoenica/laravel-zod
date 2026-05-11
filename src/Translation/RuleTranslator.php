<?php

declare(strict_types=1);

namespace LaravelZod\Translation;

use Illuminate\Validation\Rules\Enum as EnumRule;
use LaravelZod\Contracts\HasZodSchema;
use LaravelZod\Schema\Constraint;
use LaravelZod\Schema\CrossFieldRefine;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionObject;
use RuntimeException;

final class RuleTranslator
{
    /** @var string[] */
    public array $serverOnlyRules = ['exists', 'unique', 'current_password'];

    /** 'comment' or 'fail' */
    public string $serverOnlyBehaviour = 'comment';

    public bool $customRulesStrict = false;

    /** @var string[] Warnings emitted during translation. */
    public array $warnings = [];

    public function __construct(
        private readonly MessageResolver $messages,
    ) {}

    /**
     * Apply one parsed rule to a property.
     *
     * @param  string|object  $rule  Rule name like 'min', or a Rule object instance.
     * @param  array<int, string>  $params  Rule parameters (e.g. ['160'] for max:160).
     * @param  string[]  $fieldNames  All sibling field names (for cross-field detection).
     */
    public function apply(
        PropertySchema $prop,
        string|object $rule,
        array $params,
        ObjectSchema $obj,
        array $fieldNames,
    ): void {
        if (is_object($rule)) {
            $this->applyObjectRule($prop, $rule);

            return;
        }

        if (in_array($rule, $this->serverOnlyRules, true)) {
            if ($this->serverOnlyBehaviour === 'fail') {
                throw new RuntimeException("Server-only rule '{$rule}' on field '{$prop->name}' cannot be translated.");
            }
            $prop->comments[] = "server-only: {$rule}".($params === [] ? '' : ':'.implode(',', $params));

            return;
        }

        match ($rule) {
            // ─── Modifiers / presence ──────────────────────────────────────
            'nullable' => $prop->nullable = true,
            'sometimes' => $prop->optional = true,
            'bail' => null,
            'present' => $this->requirePresent($prop),
            'filled' => $this->requireFilledIfPresent($prop),
            'required' => $this->requireValue($prop),
            'required_if' => $this->requiredIf($prop, $obj, $params),
            'required_if_accepted' => $this->requiredIfAccepted($prop, $obj, $params, accepted: true),
            'required_if_declined' => $this->requiredIfAccepted($prop, $obj, $params, accepted: false),
            'required_unless' => $this->requiredUnless($prop, $obj, $params),
            'required_with' => $this->requiredWith($prop, $obj, $params, all: false),
            'required_with_all' => $this->requiredWith($prop, $obj, $params, all: true),
            'required_without' => $this->requiredWithout($prop, $obj, $params, all: false),
            'required_without_all' => $this->requiredWithout($prop, $obj, $params, all: true),
            'required_array_keys' => $this->requiredArrayKeys($prop, $params),

            // ─── Missing / prohibited / exclude ─────────────────────────────
            'missing' => $this->mustBeAbsent($prop, $obj),
            'missing_if' => $this->missingIf($prop, $obj, $params),
            'missing_unless' => $this->missingUnless($prop, $obj, $params),
            'missing_with' => $this->missingWith($prop, $obj, $params, all: false),
            'missing_with_all' => $this->missingWith($prop, $obj, $params, all: true),
            'prohibited' => $this->mustBeAbsent($prop, $obj),
            'prohibited_if' => $this->prohibitedIf($prop, $obj, $params),
            'prohibited_if_accepted' => $this->prohibitedIfAccepted($prop, $obj, $params, accepted: true),
            'prohibited_unless' => $this->prohibitedUnless($prop, $obj, $params),
            'prohibits' => $this->prohibits($prop, $obj, $params),
            'exclude' => $prop->exclude = true,
            'exclude_if' => $this->excludeIf($prop, $obj, $params),
            'exclude_unless' => $this->excludeIf($prop, $obj, $params, invert: true),
            'exclude_with' => $this->excludeWith($prop, $obj, $params),
            'exclude_without' => $this->excludeWithout($prop, $obj, $params),

            // ─── Accepted / declined ────────────────────────────────────────
            'accepted' => $this->accepted($prop, $obj, accepted: true),
            'accepted_if' => $this->acceptedIf($prop, $obj, $params, accepted: true),
            'declined' => $this->accepted($prop, $obj, accepted: false),
            'declined_if' => $this->acceptedIf($prop, $obj, $params, accepted: false),

            // ─── Types ──────────────────────────────────────────────────────
            'string' => $prop->type = PropertyType::STRING,
            'integer' => $prop->type = PropertyType::INTEGER,
            'numeric' => $prop->type = PropertyType::NUMBER,
            'decimal' => $this->decimal($prop, $params),
            'boolean' => $prop->type = PropertyType::BOOLEAN,
            'array' => $this->arrayType($prop, $params),
            'list' => $this->listType($prop),
            'file' => $prop->type = PropertyType::FILE,
            'image' => $this->imageType($prop),
            'json' => $this->jsonType($prop),

            // ─── String constraints ─────────────────────────────────────────
            'alpha' => $this->alpha($prop),
            'alpha_dash' => $this->alphaDash($prop),
            'alpha_num' => $this->alphaNum($prop),
            'ascii' => $this->ascii($prop),
            'lowercase' => $this->lowercase($prop),
            'uppercase' => $this->uppercase($prop),
            'starts_with' => $this->startsWith($prop, $params, negate: false),
            'doesnt_start_with' => $this->startsWith($prop, $params, negate: true),
            'ends_with' => $this->endsWith($prop, $params, negate: false),
            'doesnt_end_with' => $this->endsWith($prop, $params, negate: true),
            'contains' => $this->contains($prop, $params, negate: false),
            'doesnt_contain' => $this->contains($prop, $params, negate: true),
            'hex_color' => $this->hexColor($prop),
            'regex' => $this->regex($prop, $params, negate: false),
            'not_regex' => $this->regex($prop, $params, negate: true),

            // ─── Sized constraints (string, numeric, array all share these) ─
            'min' => $this->sizeRule($prop, 'min', $params),
            'max' => $this->sizeRule($prop, 'max', $params),
            'between' => $this->between($prop, $params),
            'size' => $this->sizeRule($prop, 'size', $params),

            // ─── Numeric constraints ────────────────────────────────────────
            'gt' => $this->compareNumeric($prop, $obj, $fieldNames, $params, '>', 'gt'),
            'gte' => $this->compareNumeric($prop, $obj, $fieldNames, $params, '>=', 'gte'),
            'lt' => $this->compareNumeric($prop, $obj, $fieldNames, $params, '<', 'lt'),
            'lte' => $this->compareNumeric($prop, $obj, $fieldNames, $params, '<=', 'lte'),
            'multiple_of' => $this->multipleOf($prop, $params),
            'digits' => $this->digits($prop, $params, exact: true),
            'digits_between' => $this->digitsBetween($prop, $params),
            'max_digits' => $this->digits($prop, $params, exact: false, max: true),
            'min_digits' => $this->digits($prop, $params, exact: false, max: false),

            // ─── Dates ──────────────────────────────────────────────────────
            'date' => $this->date($prop),
            'date_format' => $this->dateFormat($prop, $params),
            'date_equals' => $this->dateEquals($prop, $obj, $fieldNames, $params),
            'after' => $this->dateCompare($prop, $obj, $fieldNames, $params, '>'),
            'after_or_equal' => $this->dateCompare($prop, $obj, $fieldNames, $params, '>='),
            'before' => $this->dateCompare($prop, $obj, $fieldNames, $params, '<'),
            'before_or_equal' => $this->dateCompare($prop, $obj, $fieldNames, $params, '<='),
            'timezone' => $this->timezone($prop),

            // ─── Format ─────────────────────────────────────────────────────
            'email' => $this->email($prop),
            'url' => $this->url($prop),
            'active_url' => $this->activeUrl($prop),
            'uuid' => $this->uuid($prop),
            'ulid' => $this->ulid($prop),
            'ip' => $this->ip($prop, ''),
            'ipv4' => $this->ip($prop, 'v4'),
            'ipv6' => $this->ip($prop, 'v6'),
            'mac_address' => $this->macAddress($prop),

            // ─── Same / different / confirmed / in_array / distinct ─────────
            'same' => $this->same($prop, $obj, $params, equal: true),
            'different' => $this->same($prop, $obj, $params, equal: false),
            'confirmed' => $this->confirmed($prop, $obj, $params),
            'in_array' => $this->inArray($prop, $obj, $params),
            'distinct' => $this->distinct($prop, $params),

            // ─── Membership ─────────────────────────────────────────────────
            'in' => $this->in($prop, $params),
            'not_in' => $this->notIn($prop, $params),
            'enum' => $this->enumString($prop, $params),

            // ─── File ───────────────────────────────────────────────────────
            'mimes' => $this->mimes($prop, $params, mimeTypes: false),
            'mimetypes' => $this->mimes($prop, $params, mimeTypes: true),
            'extensions' => $this->extensions($prop, $params),
            'dimensions' => $this->dimensions($prop, $params),

            default => $this->warnings[] = "Unhandled rule '{$rule}' on field '{$prop->name}'. Skipped.",
        };
    }

    // ════════════════════════════════════════════════════════════════════════
    // Object-rule dispatch (Rule::in(...), Rule::enum(...), Closure, custom)
    // ════════════════════════════════════════════════════════════════════════

    private function applyObjectRule(PropertySchema $prop, object $rule): void
    {
        $ruleClass = $rule::class;

        if ($rule instanceof HasZodSchema) {
            $expr = $rule->toZod();
            if ($expr !== '' && $expr[0] === '.') {
                $prop->rawSuffixes[] = $expr;
            } else {
                $prop->rawSuffixes[] = '.refine((v) => true) /* '.$ruleClass.' */';
                $this->warnings[] = "Custom rule {$ruleClass} returned non-chain expression; emitted as no-op.";
            }

            return;
        }

        if ($rule instanceof EnumRule) {
            $reflection = new ReflectionObject($rule);
            $typeProp = $reflection->getProperty('type');
            $enumClass = $typeProp->getValue($rule);
            if (is_string($enumClass)) {
                $this->enumString($prop, [$enumClass]);
            }

            return;
        }

        if (method_exists($rule, '__toString')) {
            $string = (string) $rule;
            if (str_contains($string, ':')) {
                [$name, $rest] = explode(':', $string, 2);
                $params = $rest === '' ? [] : str_getcsv($rest, ',', '"', '\\');
                $tmp = new ObjectSchema('_tmp', '_tmp');
                $this->apply($prop, $name, array_map(strval(...), $params), $tmp, []);

                return;
            }
        }

        if ($this->customRulesStrict) {
            throw new RuntimeException("Custom rule {$ruleClass} does not implement HasZodSchema.");
        }

        $this->warnings[] = "Custom rule {$ruleClass} on field '{$prop->name}' has no toZod(); skipped.";
        $prop->comments[] = 'custom rule skipped: '.$ruleClass;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Presence helpers
    // ════════════════════════════════════════════════════════════════════════

    private function requireValue(PropertySchema $prop): void
    {
        $prop->required = true;
        $prop->sawRequiredFlag = true;
        $prop->optional = false;
    }

    private function requirePresent(PropertySchema $prop): void
    {
        $prop->optional = false;
    }

    private function requireFilledIfPresent(PropertySchema $prop): void
    {
        $prop->optional = true;
        $msg = $this->messages->resolve($prop->name, 'filled');
        $prop->rawSuffixes[] = $this->refine(
            "(v) => v === undefined || (v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))",
            $msg,
        );
    }

    private function mustBeAbsent(PropertySchema $prop, ObjectSchema $obj): void
    {
        $prop->optional = true;
        $msg = $this->messages->resolve($prop->name, 'prohibited');
        $body = "if (data['{$prop->name}'] !== undefined && data['{$prop->name}'] !== null && data['{$prop->name}'] !== '') {"
            ."ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Conditional presence (required_if, required_unless, required_with, …)
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function requiredIf(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $prop->optional = true;
        $msg = $this->messages->resolve($prop->name, 'required_if', $this->ruleParams('required_if', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if ({$cond}) { if (".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function requiredIfAccepted(PropertySchema $prop, ObjectSchema $obj, array $params, bool $accepted): void
    {
        $prop->optional = true;
        $other = $params[0] ?? '';
        $rule = $accepted ? 'required_if_accepted' : 'required_if_declined';
        $msg = $this->messages->resolve($prop->name, $rule, ['other' => $other]);
        $check = $accepted
            ? "[true,1,'1','yes','on','true'].includes(data['{$other}'])"
            : "[false,0,'0','no','off','false'].includes(data['{$other}'])";
        $body = "if ({$check}) { if (".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function requiredUnless(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $prop->optional = true;
        $msg = $this->messages->resolve($prop->name, 'required_unless', $this->ruleParams('required_unless', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if (!({$cond})) { if (".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function requiredWith(PropertySchema $prop, ObjectSchema $obj, array $params, bool $all): void
    {
        $prop->optional = true;
        $rule = $all ? 'required_with_all' : 'required_with';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(' / ', $params)]);
        $checks = array_map(fn (string $f): string => '!'.$this->isEmpty("data['{$f}']"), $params);
        $cond = $all ? '('.implode(' && ', $checks).')' : '('.implode(' || ', $checks).')';
        $body = "if ({$cond}) { if (".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function requiredWithout(PropertySchema $prop, ObjectSchema $obj, array $params, bool $all): void
    {
        $prop->optional = true;
        $rule = $all ? 'required_without_all' : 'required_without';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(' / ', $params)]);
        $checks = array_map(fn (string $f): string => $this->isEmpty("data['{$f}']"), $params);
        $cond = $all ? '('.implode(' && ', $checks).')' : '('.implode(' || ', $checks).')';
        $body = "if ({$cond}) { if (".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function requiredArrayKeys(PropertySchema $prop, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'required_array_keys', ['values' => implode(', ', $params)]);
        $keys = json_encode($params);
        $prop->rawSuffixes[] = $this->refine(
            "(v) => v && typeof v === 'object' && {$keys}.every((k) => k in v)",
            $msg,
        );
    }

    /** @param string[] $params */
    private function missingIf(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'missing_if', $this->ruleParams('missing_if', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if ({$cond}) { if (data['{$prop->name}'] !== undefined) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function missingUnless(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'missing_unless', $this->ruleParams('missing_unless', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if (!({$cond})) { if (data['{$prop->name}'] !== undefined) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function missingWith(PropertySchema $prop, ObjectSchema $obj, array $params, bool $all): void
    {
        $rule = $all ? 'missing_with_all' : 'missing_with';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(' / ', $params)]);
        $checks = array_map(fn (string $f): string => "data['{$f}'] !== undefined", $params);
        $cond = $all ? '('.implode(' && ', $checks).')' : '('.implode(' || ', $checks).')';
        $body = "if ({$cond}) { if (data['{$prop->name}'] !== undefined) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function prohibitedIf(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'prohibited_if', $this->ruleParams('prohibited_if', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if ({$cond}) { if (!".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function prohibitedIfAccepted(PropertySchema $prop, ObjectSchema $obj, array $params, bool $accepted): void
    {
        $other = $params[0] ?? '';
        $msg = $this->messages->resolve($prop->name, 'prohibited_if_accepted', ['other' => $other]);
        $check = $accepted
            ? "[true,1,'1','yes','on','true'].includes(data['{$other}'])"
            : "[false,0,'0','no','off','false'].includes(data['{$other}'])";
        $body = "if ({$check}) { if (!".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function prohibitedUnless(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'prohibited_unless', $this->ruleParams('prohibited_unless', $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $body = "if (!({$cond})) { if (!".$this->isEmpty("data['{$prop->name}']")
            .") ctx.addIssue({ code: 'custom', message: ".$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function prohibits(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'prohibits', ['other' => implode(' / ', $params)]);
        $checks = array_map(fn (string $f): string => '!'.$this->isEmpty("data['{$f}']"), $params);
        $cond = '('.implode(' || ', $checks).')';
        $body = 'if (!'.$this->isEmpty("data['{$prop->name}']").") { if ({$cond}) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function excludeIf(PropertySchema $prop, ObjectSchema $obj, array $params, bool $invert = false): void
    {
        $cond = $this->buildOtherFieldEqualsCondition($params);
        if ($invert) {
            $cond = "!({$cond})";
        }
        $prop->comments[] = "exclude_if: stripped on server when ({$cond})";
        $prop->optional = true;
    }

    /** @param string[] $params */
    private function excludeWith(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $prop->comments[] = 'exclude_with: stripped on server when '.implode(',', $params).' present';
        $prop->optional = true;
    }

    /** @param string[] $params */
    private function excludeWithout(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $prop->comments[] = 'exclude_without: stripped on server when '.implode(',', $params).' missing';
        $prop->optional = true;
    }

    // ════════════════════════════════════════════════════════════════════════
    // Accepted / declined
    // ════════════════════════════════════════════════════════════════════════

    private function accepted(PropertySchema $prop, ObjectSchema $obj, bool $accepted): void
    {
        $values = $accepted ? ['yes', 'on', 1, '1', true, 'true'] : ['no', 'off', 0, '0', false, 'false'];
        $literal = json_encode($values);
        $rule = $accepted ? 'accepted' : 'declined';
        $msg = $this->messages->resolve($prop->name, $rule);
        // Accept boolean/numeric/string forms — don't let the analyzer coerce
        // this field to STRING after all rules run.
        $prop->preventDefaultType = true;
        $prop->rawSuffixes[] = $this->refine("(v: unknown) => {$literal}.includes(v as never)", $msg);
    }

    /** @param string[] $params */
    private function acceptedIf(PropertySchema $prop, ObjectSchema $obj, array $params, bool $accepted): void
    {
        $rule = $accepted ? 'accepted_if' : 'declined_if';
        $msg = $this->messages->resolve($prop->name, $rule, $this->ruleParams($rule, $params));
        $cond = $this->buildOtherFieldEqualsCondition($params);
        $values = $accepted
            ? "[true,1,'1','yes','on','true']"
            : "[false,0,'0','no','off','false']";
        $body = "if ({$cond}) { if (!{$values}.includes(data['{$prop->name}'])) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Type coercion / specialisation
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function arrayType(PropertySchema $prop, array $params): void
    {
        $prop->type = PropertyType::ARRAY;
        if ($params !== []) {
            // Restrict allowed keys
            $keys = json_encode($params);
            $msg = $this->messages->resolve($prop->name, 'array', ['values' => implode(', ', $params)]);
            $prop->rawSuffixes[] = $this->refine(
                "(v) => Array.isArray(v) || (v && typeof v === 'object' && Object.keys(v).every((k) => {$keys}.includes(k)))",
                $msg,
            );
        }
    }

    private function listType(PropertySchema $prop): void
    {
        $prop->type = PropertyType::ARRAY;
        $msg = $this->messages->resolve($prop->name, 'list');
        $prop->rawSuffixes[] = $this->refine('(v) => Array.isArray(v)', $msg);
    }

    private function imageType(PropertySchema $prop): void
    {
        $prop->type = PropertyType::FILE;
        $msg = $this->messages->resolve($prop->name, 'image');
        $prop->rawSuffixes[] = $this->refine(
            '(v) => v instanceof File && /^image\\//.test(v.type)',
            $msg,
        );
    }

    private function jsonType(PropertySchema $prop): void
    {
        if ($prop->type === PropertyType::ANY) {
            $prop->type = PropertyType::STRING;
        }
        $msg = $this->messages->resolve($prop->name, 'json');
        $prop->rawSuffixes[] = $this->refine(
            "(v) => { if (typeof v !== 'string') return false; try { JSON.parse(v); return true; } catch { return false; } }",
            $msg,
        );
    }

    /** @param string[] $params */
    private function decimal(PropertySchema $prop, array $params): void
    {
        $prop->type = PropertyType::NUMBER;
        $min = (int) ($params[0] ?? 0);
        $max = isset($params[1]) ? (int) $params[1] : $min;
        $msg = $this->messages->resolve($prop->name, 'decimal', ['decimal' => "{$min}-{$max}"]);
        $prop->rawSuffixes[] = $this->refine(
            "(v) => { const s = String(v); const dot = s.indexOf('.'); const places = dot === -1 ? 0 : s.length - dot - 1; return places >= {$min} && places <= {$max}; }",
            $msg,
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // String constraints
    // ════════════════════════════════════════════════════════════════════════

    private function alpha(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'alpha');
        $prop->addConstraint(new Constraint('regex', ['/^[a-zA-Z]+$/'], $msg));
    }

    private function alphaDash(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'alpha_dash');
        $prop->addConstraint(new Constraint('regex', ['/^[a-zA-Z0-9_-]+$/'], $msg));
    }

    private function alphaNum(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'alpha_num');
        $prop->addConstraint(new Constraint('regex', ['/^[a-zA-Z0-9]+$/'], $msg));
    }

    private function ascii(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'ascii');
        $prop->addConstraint(new Constraint('regex', ['/^[\\x00-\\x7F]*$/'], $msg));
    }

    private function lowercase(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'lowercase');
        $prop->rawSuffixes[] = $this->refine("(v) => typeof v === 'string' && v === v.toLowerCase()", $msg);
    }

    private function uppercase(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'uppercase');
        $prop->rawSuffixes[] = $this->refine("(v) => typeof v === 'string' && v === v.toUpperCase()", $msg);
    }

    /** @param string[] $params */
    private function startsWith(PropertySchema $prop, array $params, bool $negate): void
    {
        $rule = $negate ? 'doesnt_start_with' : 'starts_with';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(', ', $params)]);
        $list = json_encode($params);
        $check = $negate
            ? "(v) => typeof v === 'string' && !{$list}.some((p) => v.startsWith(p))"
            : "(v) => typeof v === 'string' && {$list}.some((p) => v.startsWith(p))";
        $prop->rawSuffixes[] = $this->refine($check, $msg);
    }

    /** @param string[] $params */
    private function endsWith(PropertySchema $prop, array $params, bool $negate): void
    {
        $rule = $negate ? 'doesnt_end_with' : 'ends_with';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(', ', $params)]);
        $list = json_encode($params);
        $check = $negate
            ? "(v) => typeof v === 'string' && !{$list}.some((p) => v.endsWith(p))"
            : "(v) => typeof v === 'string' && {$list}.some((p) => v.endsWith(p))";
        $prop->rawSuffixes[] = $this->refine($check, $msg);
    }

    /** @param string[] $params */
    private function contains(PropertySchema $prop, array $params, bool $negate): void
    {
        $rule = $negate ? 'doesnt_contain' : 'contains';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(', ', $params)]);
        $list = json_encode($params);
        $check = $negate
            ? "(v) => typeof v === 'string' && !{$list}.some((p) => v.includes(p))"
            : "(v) => typeof v === 'string' && {$list}.every((p) => v.includes(p))";
        $prop->rawSuffixes[] = $this->refine($check, $msg);
    }

    private function hexColor(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'hex_color');
        $prop->addConstraint(new Constraint('regex', ['/^#?([0-9a-fA-F]{3}){1,2}$/'], $msg));
    }

    /** @param string[] $params */
    private function regex(PropertySchema $prop, array $params, bool $negate): void
    {
        $pattern = $params[0] ?? '/.*/';
        $rule = $negate ? 'not_regex' : 'regex';
        $msg = $this->messages->resolve($prop->name, $rule);
        if ($negate) {
            $prop->rawSuffixes[] = $this->refine("(v) => typeof v === 'string' && !{$pattern}.test(v)", $msg);
        } else {
            $prop->addConstraint(new Constraint('regex', [$pattern], $msg));
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Sized constraints (min / max / between / size) - polymorphic
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function sizeRule(PropertySchema $prop, string $variant, array $params): void
    {
        $n = $params[0] ?? '0';
        $hint = $this->typeHint($prop);
        $msg = $this->messages->resolve($prop->name, $variant, $this->ruleParams($variant, $params), $hint);

        $method = match ($variant) {
            'min', 'max' => $variant,
            'size' => match ($prop->type) {
                PropertyType::STRING => 'length',
                PropertyType::ARRAY => 'length',
                default => '__size_refine',
            },
            default => $variant,
        };

        if ($method === '__size_refine') {
            $prop->rawSuffixes[] = $this->refine("(v) => Number(v) === {$n}", $msg);

            return;
        }

        $prop->addConstraint(new Constraint($method, [(int) $n], $msg));
    }

    /** @param string[] $params */
    private function between(PropertySchema $prop, array $params): void
    {
        $min = (int) ($params[0] ?? 0);
        $max = (int) ($params[1] ?? 0);
        $hint = $this->typeHint($prop);
        $msg = $this->messages->resolve($prop->name, 'between', ['min' => $min, 'max' => $max], $hint);
        $prop->addConstraint(new Constraint('min', [$min], $msg));
        $prop->addConstraint(new Constraint('max', [$max], $msg));
    }

    private function typeHint(PropertySchema $prop): ?string
    {
        return match ($prop->type) {
            PropertyType::STRING => 'string',
            PropertyType::INTEGER, PropertyType::NUMBER => 'numeric',
            PropertyType::ARRAY => 'array',
            PropertyType::FILE => 'file',
            default => null,
        };
    }

    /**
     * @param  string[]  $params
     * @param  string[]  $fieldNames
     */
    private function compareNumeric(
        PropertySchema $prop,
        ObjectSchema $obj,
        array $fieldNames,
        array $params,
        string $op,
        string $rule,
    ): void {
        $arg = $params[0] ?? '0';
        $msg = $this->messages->resolve($prop->name, $rule, ['value' => $arg]);

        if (in_array($arg, $fieldNames, true)) {
            $body = "if (data['{$prop->name}'] !== undefined && data['{$arg}'] !== undefined && !(Number(data['{$prop->name}']) {$op} Number(data['{$arg}']))) ctx.addIssue({ code: 'custom', message: "
                .$this->q($msg).", path: ['{$prop->name}'] });";
            $obj->addRefine(new CrossFieldRefine($prop->name, $body));

            return;
        }

        $method = match ($op) {
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
            default => 'gte',
        };
        $prop->addConstraint(new Constraint($method, [(int) $arg], $msg));
    }

    /** @param string[] $params */
    private function multipleOf(PropertySchema $prop, array $params): void
    {
        $n = $params[0] ?? '1';
        $msg = $this->messages->resolve($prop->name, 'multiple_of', ['value' => $n]);
        $prop->addConstraint(new Constraint('multipleOf', [(float) $n], $msg));
    }

    /** @param string[] $params */
    private function digits(PropertySchema $prop, array $params, bool $exact, bool $max = false): void
    {
        $n = (int) ($params[0] ?? 0);
        $rule = $exact ? 'digits' : ($max ? 'max_digits' : 'min_digits');
        $msg = $this->messages->resolve($prop->name, $rule, ['digits' => $n]);
        if ($exact) {
            $check = "(v) => /^\\d+$/.test(String(v)) && String(v).length === {$n}";
        } elseif ($max) {
            $check = "(v) => /^\\d+$/.test(String(v)) && String(v).length <= {$n}";
        } else {
            $check = "(v) => /^\\d+$/.test(String(v)) && String(v).length >= {$n}";
        }
        $prop->rawSuffixes[] = $this->refine($check, $msg);
    }

    /** @param string[] $params */
    private function digitsBetween(PropertySchema $prop, array $params): void
    {
        $min = (int) ($params[0] ?? 0);
        $max = (int) ($params[1] ?? 0);
        $msg = $this->messages->resolve($prop->name, 'digits_between', ['min' => $min, 'max' => $max]);
        $prop->rawSuffixes[] = $this->refine(
            "(v) => /^\\d+$/.test(String(v)) && String(v).length >= {$min} && String(v).length <= {$max}",
            $msg,
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Dates
    // ════════════════════════════════════════════════════════════════════════

    private function date(PropertySchema $prop): void
    {
        if ($prop->type === PropertyType::ANY) {
            $prop->type = PropertyType::STRING;
        }
        $msg = $this->messages->resolve($prop->name, 'date');
        $prop->rawSuffixes[] = $this->refine(
            "(v) => typeof v === 'string' && !Number.isNaN(Date.parse(v))",
            $msg,
        );
    }

    /** @param string[] $params */
    private function dateFormat(PropertySchema $prop, array $params): void
    {
        $fmt = $params[0] ?? '';
        $msg = $this->messages->resolve($prop->name, 'date_format', ['format' => $fmt]);
        // Best-effort: just check it's a parseable date string. Format-exact match would need a JS date-format lib.
        $prop->rawSuffixes[] = $this->refine(
            "(v) => typeof v === 'string' && !Number.isNaN(Date.parse(v)) /* expected format: {$fmt} */",
            $msg,
        );
    }

    /**
     * @param  string[]  $params
     * @param  string[]  $fieldNames
     */
    private function dateEquals(PropertySchema $prop, ObjectSchema $obj, array $fieldNames, array $params): void
    {
        $arg = $params[0] ?? '';
        $msg = $this->messages->resolve($prop->name, 'date_equals', ['date' => $arg]);
        $reference = in_array($arg, $fieldNames, true)
            ? "Date.parse(String(data['{$arg}']))"
            : 'Date.parse('.$this->q($arg).')';
        $body = "if (data['{$prop->name}'] !== undefined && Date.parse(String(data['{$prop->name}'])) !== {$reference}) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] });";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /**
     * @param  string[]  $params
     * @param  string[]  $fieldNames
     */
    private function dateCompare(
        PropertySchema $prop,
        ObjectSchema $obj,
        array $fieldNames,
        array $params,
        string $op,
    ): void {
        $arg = $params[0] ?? '';
        $rule = match ($op) {
            '>' => 'after',
            '>=' => 'after_or_equal',
            '<' => 'before',
            '<=' => 'before_or_equal',
            default => 'after',
        };
        $msg = $this->messages->resolve($prop->name, $rule, ['date' => $arg]);

        if (in_array($arg, ['now', 'today', 'tomorrow', 'yesterday'], true)) {
            $reference = match ($arg) {
                'now' => 'Date.now()',
                'today' => '(() => { const d = new Date(); d.setHours(0,0,0,0); return d.getTime(); })()',
                'tomorrow' => '(() => { const d = new Date(); d.setHours(0,0,0,0); return d.getTime() + 86400000; })()',
                'yesterday' => '(() => { const d = new Date(); d.setHours(0,0,0,0); return d.getTime() - 86400000; })()',
            };
        } elseif (in_array($arg, $fieldNames, true)) {
            $reference = "Date.parse(String(data['{$arg}']))";
        } else {
            $reference = 'Date.parse('.$this->q($arg).')';
        }

        $body = "if (data['{$prop->name}'] !== undefined && data['{$prop->name}'] !== null && data['{$prop->name}'] !== '') { "
            ."const t = Date.parse(String(data['{$prop->name}'])); const r = {$reference}; "
            ."if (!Number.isNaN(t) && !Number.isNaN(r) && !(t {$op} r)) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] }); }";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    private function timezone(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'timezone');
        $prop->rawSuffixes[] = $this->refine(
            '(v) => { try { Intl.DateTimeFormat(undefined, { timeZone: String(v) }); return true; } catch { return false; } }',
            $msg,
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Format helpers (email, url, uuid, ip, ...)
    // ════════════════════════════════════════════════════════════════════════

    private function email(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'email');
        $prop->addConstraint(new Constraint('email', [], $msg));
    }

    private function url(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'url');
        $prop->addConstraint(new Constraint('url', [], $msg));
    }

    private function activeUrl(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'active_url');
        $prop->addConstraint(new Constraint('url', [], $msg));
        $prop->comments[] = 'active_url: DNS-resolution check is server-only';
    }

    private function uuid(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'uuid');
        $prop->addConstraint(new Constraint('uuid', [], $msg));
    }

    private function ulid(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'ulid');
        $prop->addConstraint(new Constraint('regex', ['/^[0-9A-HJKMNP-TV-Z]{26}$/'], $msg));
    }

    private function ip(PropertySchema $prop, string $kind): void
    {
        $msg = $this->messages->resolve($prop->name, 'ip'.$kind);
        $method = match ($kind) {
            'v4' => 'ipv4',
            'v6' => 'ipv6',
            default => 'ipv4', // Zod 4 has both; 'ip' is removed. Use ipv4 as default and add ipv6 refine fallback.
        };
        if ($kind === '') {
            // accept either v4 or v6
            $prop->rawSuffixes[] = $this->refine(
                "(v) => { if (typeof v !== 'string') return false; const v4 = /^(\\d{1,3}\\.){3}\\d{1,3}$/; const v6 = /^[0-9a-fA-F:]+$/; return v4.test(v) || v6.test(v); }",
                $msg,
            );

            return;
        }
        $prop->addConstraint(new Constraint($method, [], $msg));
    }

    private function macAddress(PropertySchema $prop): void
    {
        $msg = $this->messages->resolve($prop->name, 'mac_address');
        $prop->addConstraint(new Constraint('regex', ['/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'], $msg));
    }

    // ════════════════════════════════════════════════════════════════════════
    // Same / different / confirmed / in_array / distinct
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function same(PropertySchema $prop, ObjectSchema $obj, array $params, bool $equal): void
    {
        $other = $params[0] ?? '';
        $rule = $equal ? 'same' : 'different';
        $msg = $this->messages->resolve($prop->name, $rule, ['other' => $other]);
        $check = $equal
            ? "data['{$prop->name}'] !== data['{$other}']"
            : "data['{$prop->name}'] === data['{$other}']";
        $body = "if (data['{$prop->name}'] !== undefined && data['{$other}'] !== undefined && {$check}) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] });";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function confirmed(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $other = $params[0] ?? ($prop->name.'_confirmation');
        $msg = $this->messages->resolve($prop->name, 'confirmed');
        $body = "if (data['{$prop->name}'] !== data['{$other}']) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$other}'] });";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));

        // Add the companion confirmation field to the schema so Zod doesn't
        // strip it before the superRefine block sees it. The field mirrors
        // the source field's type but is always optional (Laravel doesn't
        // require <field>_confirmation to be filled when <field> is empty).
        $exists = false;
        foreach ($obj->properties as $existing) {
            if ($existing->name === $other) {
                $exists = true;
                break;
            }
        }
        if (! $exists) {
            $companion = new PropertySchema($other, $prop->type);
            $companion->optional = true;
            $obj->addProperty($companion);
        }
    }

    /** @param string[] $params */
    private function inArray(PropertySchema $prop, ObjectSchema $obj, array $params): void
    {
        $other = $params[0] ?? '';
        $other = str_replace('.*', '', $other);
        $msg = $this->messages->resolve($prop->name, 'in_array', ['other' => $other]);
        $body = "if (Array.isArray(data['{$other}']) && !data['{$other}'].includes(data['{$prop->name}'])) ctx.addIssue({ code: 'custom', message: "
            .$this->q($msg).", path: ['{$prop->name}'] });";
        $obj->addRefine(new CrossFieldRefine($prop->name, $body));
    }

    /** @param string[] $params */
    private function distinct(PropertySchema $prop, array $params): void
    {
        $caseInsensitive = in_array('ignore_case', $params, true);
        $msg = $this->messages->resolve($prop->name, 'distinct');
        $key = $caseInsensitive
            ? "(x: unknown) => typeof x === 'string' ? x.toLowerCase() : x"
            : '(x: unknown) => x';
        $prop->rawSuffixes[] = $this->refine(
            "(v) => { if (!Array.isArray(v)) return true; const k = ({$key}); return new Set(v.map(k)).size === v.length; }",
            $msg,
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // Membership
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function in(PropertySchema $prop, array $params): void
    {
        /** @var list<int|string> $values */
        $values = array_values(array_map($this->castEnumValue(...), $params));
        $prop->enumValues = $values;
        $prop->type = PropertyType::ENUM;
    }

    /** @param string[] $params */
    private function notIn(PropertySchema $prop, array $params): void
    {
        $list = json_encode($params);
        $msg = $this->messages->resolve($prop->name, 'not_in');
        $prop->rawSuffixes[] = $this->refine("(v) => !{$list}.includes(String(v))", $msg);
    }

    /** @param string[] $params */
    private function enumString(PropertySchema $prop, array $params): void
    {
        $class = $params[0] ?? '';
        if ($class !== '' && enum_exists($class)) {
            $reflection = new ReflectionEnum($class);
            if ($reflection->isBacked()) {
                /** @var list<int|string> $values */
                $values = [];
                $cases = $reflection->getCases();
                if (! is_array($cases)) {
                    $cases = [];
                }
                foreach ($cases as $case) {
                    if ($case instanceof ReflectionEnumBackedCase) {
                        $values[] = $case->getBackingValue();
                    }
                }
                $prop->enumValues = $values;
                $prop->type = PropertyType::ENUM;

                return;
            }
        }
        $this->warnings[] = "enum rule on '{$prop->name}' could not resolve {$class} as a backed enum.";
    }

    private function castEnumValue(string $raw): string|int
    {
        return is_numeric($raw) ? (int) $raw : $raw;
    }

    // ════════════════════════════════════════════════════════════════════════
    // File rules
    // ════════════════════════════════════════════════════════════════════════

    /** @param string[] $params */
    private function mimes(PropertySchema $prop, array $params, bool $mimeTypes): void
    {
        $rule = $mimeTypes ? 'mimetypes' : 'mimes';
        $msg = $this->messages->resolve($prop->name, $rule, ['values' => implode(', ', $params)]);
        $list = json_encode($params);
        $check = $mimeTypes
            ? "(v) => v instanceof File && {$list}.includes(v.type)"
            : "(v) => v instanceof File && {$list}.some((ext) => v.name.toLowerCase().endsWith('.' + ext.toLowerCase()))";
        $prop->rawSuffixes[] = $this->refine($check, $msg);
    }

    /** @param string[] $params */
    private function extensions(PropertySchema $prop, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'extensions', ['values' => implode(', ', $params)]);
        $list = json_encode($params);
        $prop->rawSuffixes[] = $this->refine(
            "(v) => v instanceof File && {$list}.some((ext) => v.name.toLowerCase().endsWith('.' + ext.toLowerCase()))",
            $msg,
        );
    }

    /** @param string[] $params */
    private function dimensions(PropertySchema $prop, array $params): void
    {
        $msg = $this->messages->resolve($prop->name, 'dimensions');
        $rules = [];
        foreach ($params as $param) {
            if (! str_contains($param, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $param, 2);
            $rules[$k] = (int) $v;
        }
        $checks = [];
        foreach ($rules as $k => $v) {
            $checks[] = match ($k) {
                'min_width' => "img.width >= {$v}",
                'max_width' => "img.width <= {$v}",
                'min_height' => "img.height >= {$v}",
                'max_height' => "img.height <= {$v}",
                'width' => "img.width === {$v}",
                'height' => "img.height === {$v}",
                'ratio' => 'true', // ratio refine is non-trivial; leave as no-op
                default => 'true',
            };
        }
        $body = '() => true /* dimensions: client-side image-dimension check needs async resolution; deferred to server */';
        $prop->rawSuffixes[] = $this->refine($body, $msg);
        $prop->comments[] = 'dimensions: '.implode(', ', $params).' (verified server-side)';
    }

    // ════════════════════════════════════════════════════════════════════════
    // Cross-field helpers
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Build a JS expression that checks if `data[other]` equals any of the listed values.
     * Param shape: ["other_field", "val1", "val2", ...]
     *
     * @param  string[]  $params
     */
    private function buildOtherFieldEqualsCondition(array $params): string
    {
        $other = $params[0] ?? '';
        $values = array_slice($params, 1);
        if ($values === []) {
            return "data['{$other}'] !== undefined";
        }
        $literal = json_encode($values);

        return "{$literal}.includes(String(data['{$other}']))";
    }

    private function isEmpty(string $jsExpr): string
    {
        return "({$jsExpr} === undefined || {$jsExpr} === null || {$jsExpr} === '' || (Array.isArray({$jsExpr}) && {$jsExpr}.length === 0))";
    }

    private function refine(string $check, string $message): string
    {
        return ".refine({$check}, ".$this->q($message).')';
    }

    private function q(string $s): string
    {
        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $s).'"';
    }

    /**
     * @param  string[]  $params
     * @return array<string, string>
     */
    private function ruleParams(string $rule, array $params): array
    {
        return match ($rule) {
            'min', 'max', 'size' => ['min' => $params[0] ?? '', 'max' => $params[0] ?? '', 'size' => $params[0] ?? ''],
            'between' => ['min' => $params[0] ?? '', 'max' => $params[1] ?? ''],
            'required_if', 'missing_if', 'prohibited_if' => ['other' => $params[0] ?? '', 'value' => $params[1] ?? ''],
            'required_unless', 'missing_unless', 'prohibited_unless' => ['other' => $params[0] ?? '', 'values' => implode(', ', array_slice($params, 1))],
            'accepted_if', 'declined_if' => ['other' => $params[0] ?? '', 'value' => $params[1] ?? ''],
            default => [],
        };
    }
}
