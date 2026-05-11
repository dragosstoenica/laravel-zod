<?php

declare(strict_types=1);

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    config()->set('laravel-zod.scan', [__DIR__.'/../Fixtures']);
    config()->set('laravel-zod.output', sys_get_temp_dir().'/laravel-zod-test-'.uniqid().'.ts');
});

it('runs zod:generate against the fixture classes and exits successfully', function (): void {
    artisan('zod:generate')->assertSuccessful();
});

it('writes a file with the expected exports in --dry-run mode', function (): void {
    artisan('zod:generate', ['--dry-run' => true])
        ->expectsOutputToContain('UserDataSchema')
        ->expectsOutputToContain('EventDataSchema')
        ->expectsOutputToContain('StoreEventRequestSchema')
        ->assertSuccessful();
});

it('writes a non-empty TS file with z.object schemas', function (): void {
    $output = config()->string('laravel-zod.output');
    artisan('zod:generate')->assertSuccessful();
    expect(file_exists($output))->toBeTrue();
    $contents = (string) file_get_contents($output);
    expect($contents)->toContain('export const UserDataSchema = z');
    expect($contents)->toContain('.object({');
    expect($contents)->toContain("import { z } from 'zod';");
    @unlink($output);
});

it('emits superRefine for cross-field rules', function (): void {
    $output = config()->string('laravel-zod.output');
    artisan('zod:generate')->assertSuccessful();
    $contents = (string) file_get_contents($output);
    expect($contents)->toContain('.superRefine((data: any, ctx) => {');
    @unlink($output);
});
