<?php

declare(strict_types=1);

use LaravelZod\Schema\SchemaRegistry;

it('registers and looks up schemas by FQN', function (): void {
    $registry = new SchemaRegistry;
    $registry->register('App\\Models\\User', 'UserSchema');

    expect($registry->has('App\\Models\\User'))->toBeTrue();
    expect($registry->lookup('App\\Models\\User'))->toBe('UserSchema');
});

it('strips a leading backslash on lookup', function (): void {
    $registry = new SchemaRegistry;
    $registry->register('App\\Models\\User', 'UserSchema');

    expect($registry->has('\\App\\Models\\User'))->toBeTrue();
    expect($registry->lookup('\\App\\Models\\User'))->toBe('UserSchema');
});

it('returns null for unknown FQN', function (): void {
    $registry = new SchemaRegistry;
    expect($registry->has('Foo\\Bar'))->toBeFalse();
    expect($registry->lookup('Foo\\Bar'))->toBeNull();
});
