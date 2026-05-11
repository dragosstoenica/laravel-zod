<?php

declare(strict_types=1);

use LaravelZod\Analyzers\DataClassAnalyzer;
use LaravelZod\Schema\PropertyType;
use LaravelZod\Schema\SchemaRegistry;
use LaravelZod\Tests\Fixtures\Data\EventData;
use LaravelZod\Tests\Fixtures\Data\UserData;

it('detects scalar types from typed constructor params', function (): void {
    $registry = new SchemaRegistry;
    $analyzer = new DataClassAnalyzer($registry);

    $schema = $analyzer->analyze(new ReflectionClass(UserData::class), 'UserDataSchema');

    expect($schema->properties)->toHaveCount(3);
    expect($schema->properties[0]->name)->toBe('id');
    expect($schema->properties[0]->type)->toBe(PropertyType::INTEGER);
    expect($schema->properties[1]->type)->toBe(PropertyType::STRING);
});

it('resolves a nullable ?UserData to a registered reference', function (): void {
    $registry = new SchemaRegistry;
    $registry->register(UserData::class, 'UserDataSchema');
    $analyzer = new DataClassAnalyzer($registry);

    $schema = $analyzer->analyze(new ReflectionClass(EventData::class), 'EventDataSchema');

    $host = collect($schema->properties)->firstWhere('name', 'host');
    expect($host->type)->toBe(PropertyType::REF);
    expect($host->reference)->toBe('UserDataSchema');
    expect($host->nullable)->toBeTrue();
});

it('resolves @var UserData[] arrays to array-of-ref', function (): void {
    $registry = new SchemaRegistry;
    $registry->register(UserData::class, 'UserDataSchema');
    $analyzer = new DataClassAnalyzer($registry);

    $schema = $analyzer->analyze(new ReflectionClass(EventData::class), 'EventDataSchema');

    $attendees = collect($schema->properties)->firstWhere('name', 'attendees');
    expect($attendees->type)->toBe(PropertyType::ARRAY);
    expect($attendees->arrayItem?->type)->toBe(PropertyType::REF);
    expect($attendees->arrayItem?->reference)->toBe('UserDataSchema');
});

it('detects Carbon types as DATE', function (): void {
    $registry = new SchemaRegistry;
    $analyzer = new DataClassAnalyzer($registry);

    $schema = $analyzer->analyze(new ReflectionClass(EventData::class), 'EventDataSchema');
    $startsAt = collect($schema->properties)->firstWhere('name', 'starts_at');
    expect($startsAt->type)->toBe(PropertyType::DATE);
});

it('detects backed enum types', function (): void {
    $registry = new SchemaRegistry;
    $analyzer = new DataClassAnalyzer($registry);

    $schema = $analyzer->analyze(new ReflectionClass(EventData::class), 'EventDataSchema');
    $status = collect($schema->properties)->firstWhere('name', 'status');
    expect($status->type)->toBe(PropertyType::ENUM);
    expect($status->enumValues)->toBe(['draft', 'published', 'archived']);
});
