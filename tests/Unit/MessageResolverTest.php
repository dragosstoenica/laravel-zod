<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use LaravelZod\Translation\MessageResolver;

function translator(): Translator
{
    $loader = new ArrayLoader;
    $loader->addMessages('en', 'validation', [
        'required' => 'The :attribute field is required.',
        'max' => [
            'string' => 'The :attribute must not exceed :max characters.',
            'numeric' => 'The :attribute must not exceed :max.',
        ],
    ]);
    $loader->addMessages('ro', 'validation', [
        'required' => 'Câmpul :attribute este obligatoriu.',
    ]);

    return new Translator($loader, 'en');
}

it('resolves from Laravel translation files', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    $msg = $resolver->resolve('email', 'required');
    expect($msg)->toBe('The email field is required.');
});

it('uses locale-specific translation when provided', function (): void {
    $resolver = new MessageResolver(translator(), 'ro');
    $msg = $resolver->resolve('email', 'required');
    expect($msg)->toBe('Câmpul email este obligatoriu.');
});

it('prefers exact override from messages()', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    $resolver->setRequestContext(['title.required' => 'Pick a name.'], []);
    expect($resolver->resolve('title', 'required'))->toBe('Pick a name.');
});

it('matches wildcard pattern', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    $resolver->setRequestContext(['*.required' => 'Required: :attribute'], []);
    expect($resolver->resolve('name', 'required'))->toBe('Required: name');
});

it('falls back to headline cased rule name', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    expect($resolver->resolve('field', 'nonexistent_rule'))
        ->toContain('Nonexistent Rule');
});

it('uses type hint to pick from sub-keyed templates', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    $msg = $resolver->resolve('name', 'max', ['max' => 100], 'string');
    expect($msg)->toBe('The name must not exceed 100 characters.');
});

it('respects attributes() custom names', function (): void {
    $resolver = new MessageResolver(translator(), 'en');
    $resolver->setRequestContext([], ['title' => 'event name']);
    $msg = $resolver->resolve('title', 'required');
    expect($msg)->toBe('The event name field is required.');
});
