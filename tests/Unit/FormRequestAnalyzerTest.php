<?php

declare(strict_types=1);

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use LaravelZod\Analyzers\FormRequestAnalyzer;
use LaravelZod\Schema\PropertyType;
use LaravelZod\Tests\Fixtures\Requests\StoreEventRequest;
use LaravelZod\Translation\MessageResolver;
use LaravelZod\Translation\RuleTranslator;

function analyzer(): FormRequestAnalyzer
{
    $loader = new ArrayLoader;
    $loader->addMessages('en', 'validation', [
        'required' => 'The :attribute field is required.',
        'max' => ['string' => 'too long'],
        'after' => 'must be after :date',
    ]);
    $t = new Translator($loader, 'en');
    $messages = new MessageResolver($t, 'en');

    return new FormRequestAnalyzer(new RuleTranslator($messages), $messages);
}

it('parses string-piped rules', function (): void {
    $schema = analyzer()->analyze(new ReflectionClass(StoreEventRequest::class), 'StoreEventRequestSchema');

    expect($schema->isInputSchema)->toBeTrue();
    expect($schema->properties)->toHaveCount(4);
});

it('marks fields with required as having a min(1) constraint on strings', function (): void {
    $schema = analyzer()->analyze(new ReflectionClass(StoreEventRequest::class), 'StoreEventRequestSchema');

    $title = collect($schema->properties)->firstWhere('name', 'title');
    expect($title->type)->toBe(PropertyType::STRING);
    expect($title->hasConstraint('min'))->toBeTrue();
    expect($title->hasConstraint('max'))->toBeTrue();
});

it('emits a cross-field refine for after:starts_at', function (): void {
    $schema = analyzer()->analyze(new ReflectionClass(StoreEventRequest::class), 'StoreEventRequestSchema');
    expect($schema->crossFieldRefines)->not->toBeEmpty();
});

it('marks nullable fields nullable', function (): void {
    $schema = analyzer()->analyze(new ReflectionClass(StoreEventRequest::class), 'StoreEventRequestSchema');
    $capacity = collect($schema->properties)->firstWhere('name', 'capacity');
    expect($capacity->nullable)->toBeTrue();
});
