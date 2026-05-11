<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use LaravelZod\Schema\ObjectSchema;
use LaravelZod\Schema\PropertySchema;
use LaravelZod\Schema\PropertyType;
use LaravelZod\Translation\MessageResolver;
use LaravelZod\Translation\RuleTranslator;

/**
 * Build a fresh translator+ruletranslator. The translator has only built-in
 * Laravel validation strings populated (the bare minimum needed for messages).
 */
function makeTranslator(): RuleTranslator
{
    $loader = new ArrayLoader;
    $loader->addMessages('en', 'validation', [
        'required' => 'The :attribute field is required.',
        'max' => [
            'string' => 'The :attribute field must not be greater than :max characters.',
            'numeric' => 'The :attribute field must not be greater than :max.',
            'array' => 'The :attribute field must not have more than :max items.',
        ],
        'min' => [
            'string' => 'The :attribute field must be at least :min characters.',
            'numeric' => 'The :attribute field must be at least :min.',
            'array' => 'The :attribute field must have at least :min items.',
        ],
        'email' => 'The :attribute must be a valid email.',
        'url' => 'The :attribute must be a valid URL.',
        'in' => 'The selected :attribute is invalid.',
    ]);
    $t = new Translator($loader, 'en');

    return new RuleTranslator(new MessageResolver($t, 'en'));
}

function prop(string $name = 'field', PropertyType $type = PropertyType::ANY): PropertySchema
{
    return new PropertySchema($name, $type);
}

function obj(): ObjectSchema
{
    return new ObjectSchema('TestSchema', 'TestClass');
}

// ── Presence ──────────────────────────────────────────────────────────────────
it('applies required', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'required', [], obj(), []);
    expect($p->required)->toBeTrue();
    expect($p->sawRequiredFlag)->toBeTrue();
});

it('applies nullable', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'nullable', [], obj(), []);
    expect($p->nullable)->toBeTrue();
});

it('applies sometimes', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'sometimes', [], obj(), []);
    expect($p->optional)->toBeTrue();
});

it('treats bail as a no-op', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'bail', [], obj(), []);
    expect($p->constraints)->toHaveCount(0);
});

// ── Types ─────────────────────────────────────────────────────────────────────
it('sets string type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'string', [], obj(), []);
    expect($p->type)->toBe(PropertyType::STRING);
});

it('sets integer type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'integer', [], obj(), []);
    expect($p->type)->toBe(PropertyType::INTEGER);
});

it('sets numeric type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'numeric', [], obj(), []);
    expect($p->type)->toBe(PropertyType::NUMBER);
});

it('sets boolean type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'boolean', [], obj(), []);
    expect($p->type)->toBe(PropertyType::BOOLEAN);
});

it('sets array type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'array', [], obj(), []);
    expect($p->type)->toBe(PropertyType::ARRAY);
});

it('sets file type', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'file', [], obj(), []);
    expect($p->type)->toBe(PropertyType::FILE);
});

// ── String constraints ────────────────────────────────────────────────────────
it('adds alpha regex', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'alpha', [], obj(), []);
    expect($p->constraints)->toHaveCount(1);
    expect($p->constraints[0]->method)->toBe('regex');
});

it('adds alpha_dash regex', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'alpha_dash', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('regex');
});

it('adds starts_with refine', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'starts_with', ['foo', 'bar'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('startsWith');
});

it('adds ends_with refine', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'ends_with', ['.com'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('endsWith');
});

it('adds hex_color regex', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'hex_color', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('regex');
});

// ── Sized ─────────────────────────────────────────────────────────────────────
it('adds min constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'min', ['5'], obj(), []);
    expect($p->constraints[0]->method)->toBe('min');
    expect($p->constraints[0]->arguments)->toBe([5]);
});

it('adds max constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'max', ['100'], obj(), []);
    expect($p->constraints[0]->method)->toBe('max');
    expect($p->constraints[0]->arguments)->toBe([100]);
});

it('adds between as min+max', function (): void {
    $p = prop('f', PropertyType::INTEGER);
    makeTranslator()->apply($p, 'between', ['1', '10'], obj(), []);
    expect($p->constraints)->toHaveCount(2);
    expect($p->constraints[0]->method)->toBe('min');
    expect($p->constraints[1]->method)->toBe('max');
});

// ── Numeric comparisons ───────────────────────────────────────────────────────
it('adds gt constraint with literal arg', function (): void {
    $p = prop('f', PropertyType::INTEGER);
    makeTranslator()->apply($p, 'gt', ['5'], obj(), []);
    expect($p->constraints[0]->method)->toBe('gt');
});

it('emits cross-field refine when gt references another field', function (): void {
    $p = prop('end');
    $o = obj();
    makeTranslator()->apply($p, 'gt', ['start'], $o, ['start', 'end']);
    expect($o->crossFieldRefines)->toHaveCount(1);
});

it('adds multipleOf constraint', function (): void {
    $p = prop('f', PropertyType::INTEGER);
    makeTranslator()->apply($p, 'multiple_of', ['5'], obj(), []);
    expect($p->constraints[0]->method)->toBe('multipleOf');
});

it('adds digits refine', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'digits', ['4'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('length === 4');
});

// ── Formats ───────────────────────────────────────────────────────────────────
it('adds email constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'email', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('email');
});

it('adds url constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'url', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('url');
});

it('adds uuid constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'uuid', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('uuid');
});

it('adds ipv4 constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'ipv4', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('ipv4');
});

it('adds ipv6 constraint', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'ipv6', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('ipv6');
});

// ── Dates ─────────────────────────────────────────────────────────────────────
it('adds date refine', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'date', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('Date.parse');
});

it('emits cross-field refine for after:other_field', function (): void {
    $p = prop('end');
    $o = obj();
    makeTranslator()->apply($p, 'after', ['start'], $o, ['start', 'end']);
    expect($o->crossFieldRefines)->toHaveCount(1);
});

it('emits cross-field refine for after:now', function (): void {
    $p = prop('start');
    $o = obj();
    makeTranslator()->apply($p, 'after', ['now'], $o, ['start']);
    expect($o->crossFieldRefines[0]->body)->toContain('Date.now()');
});

// ── Cross-field presence ──────────────────────────────────────────────────────
it('emits refine for required_if', function (): void {
    $p = prop('end');
    $o = obj();
    makeTranslator()->apply($p, 'required_if', ['name', 'Bob'], $o, ['name', 'end']);
    expect($o->crossFieldRefines)->toHaveCount(1);
});

it('emits refine for confirmed', function (): void {
    $p = prop('password');
    $o = obj();
    makeTranslator()->apply($p, 'confirmed', [], $o, ['password', 'password_confirmation']);
    expect($o->crossFieldRefines[0]->body)->toContain('password_confirmation');
});

// ── Membership ────────────────────────────────────────────────────────────────
it('sets enum type for `in`', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'in', ['a', 'b', 'c'], obj(), []);
    expect($p->type)->toBe(PropertyType::ENUM);
    expect($p->enumValues)->toBe(['a', 'b', 'c']);
});

it('adds not_in refine', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'not_in', ['x'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('!');
});

// ── Server-only ───────────────────────────────────────────────────────────────
it('adds a comment for server-only rules', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'exists', ['users', 'id'], obj(), []);
    expect($p->comments[0])->toContain('server-only');
});

it('fails when server-only behaviour is fail', function (): void {
    $t = makeTranslator();
    $t->serverOnlyBehaviour = 'fail';
    expect(fn () => $t->apply(prop(), 'exists', [], obj(), []))
        ->toThrow(RuntimeException::class);
});

// ── Unknown rules ─────────────────────────────────────────────────────────────
it('warns and skips unhandled rules', function (): void {
    $t = makeTranslator();
    $t->apply(prop(), 'made_up_rule', [], obj(), []);
    expect($t->warnings)->toHaveCount(1);
    expect($t->warnings[0])->toContain("Unhandled rule 'made_up_rule'");
});

// ── File rules ────────────────────────────────────────────────────────────────
it('adds mimes refine', function (): void {
    $p = prop('f', PropertyType::FILE);
    makeTranslator()->apply($p, 'mimes', ['jpg', 'png'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('endsWith');
});

it('adds mimetypes refine', function (): void {
    $p = prop('f', PropertyType::FILE);
    makeTranslator()->apply($p, 'mimetypes', ['image/jpeg'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('v.type');
});

it('adds extensions refine', function (): void {
    $p = prop('f', PropertyType::FILE);
    makeTranslator()->apply($p, 'extensions', ['jpg'], obj(), []);
    expect($p->rawSuffixes[0])->toContain('endsWith');
});

it('emits dimensions no-op + comment', function (): void {
    $p = prop('f', PropertyType::FILE);
    makeTranslator()->apply($p, 'dimensions', ['min_width=100'], obj(), []);
    expect($p->comments[0])->toContain('dimensions');
});

// ── Accepted / declined / same / different ────────────────────────────────────
it('refines accepted', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'accepted', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('includes(v as never)');
    expect($p->preventDefaultType)->toBeTrue();
});

it('refines declined', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'declined', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('includes(v as never)');
    expect($p->preventDefaultType)->toBeTrue();
});

it('cross-field refines same', function (): void {
    $p = prop('a');
    $o = obj();
    makeTranslator()->apply($p, 'same', ['b'], $o, ['a', 'b']);
    expect($o->crossFieldRefines)->toHaveCount(1);
});

it('cross-field refines different', function (): void {
    $p = prop('a');
    $o = obj();
    makeTranslator()->apply($p, 'different', ['b'], $o, ['a', 'b']);
    expect($o->crossFieldRefines)->toHaveCount(1);
});

it('refines distinct on arrays', function (): void {
    $p = prop('tags', PropertyType::ARRAY);
    makeTranslator()->apply($p, 'distinct', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('Set');
});

// ── Decimal / json / list ─────────────────────────────────────────────────────
it('refines decimal', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'decimal', ['2'], obj(), []);
    expect($p->type)->toBe(PropertyType::NUMBER);
    expect($p->rawSuffixes[0])->toContain('places');
});

it('refines json', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'json', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('JSON.parse');
});

it('refines list as array', function (): void {
    $p = prop();
    makeTranslator()->apply($p, 'list', [], obj(), []);
    expect($p->type)->toBe(PropertyType::ARRAY);
    expect($p->rawSuffixes[0])->toContain('isArray');
});

// ── Timezone, mac_address, ulid, regex ────────────────────────────────────────
it('refines timezone', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'timezone', [], obj(), []);
    expect($p->rawSuffixes[0])->toContain('Intl.DateTimeFormat');
});

it('regex constraint preserves slashes', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'regex', ['/^[A-Z]+$/'], obj(), []);
    expect($p->constraints[0]->method)->toBe('regex');
    expect($p->constraints[0]->arguments[0])->toBe('/^[A-Z]+$/');
});

it('adds ulid as regex', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'ulid', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('regex');
});

it('adds mac_address as regex', function (): void {
    $p = prop('f', PropertyType::STRING);
    makeTranslator()->apply($p, 'mac_address', [], obj(), []);
    expect($p->constraints[0]->method)->toBe('regex');
});
