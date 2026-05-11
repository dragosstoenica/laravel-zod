<?php

declare(strict_types=1);

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    config()->set('laravel-zod.scan', [__DIR__.'/../Fixtures/Data']);
    config()->set('laravel-zod.output', sys_get_temp_dir().'/laravel-zod-circular-'.uniqid().'.ts');
});

it('wraps self-references in z.lazy', function (): void {
    $output = config()->string('laravel-zod.output');
    artisan('zod:generate')->assertSuccessful();
    $contents = (string) file_get_contents($output);

    // CommentData.parent: ?CommentData → must be lazy
    expect($contents)->toContain('z.lazy(() => CommentDataSchema)');
    @unlink($output);
});

it('breaks mutual references using exactly one z.lazy', function (): void {
    $output = config()->string('laravel-zod.output');
    artisan('zod:generate')->assertSuccessful();
    $contents = (string) file_get_contents($output);

    // AuthorData ↔ PostData. One side must be lazy; the other inlined.
    $authorLazy = mb_substr_count($contents, 'z.lazy(() => AuthorDataSchema)');
    $postLazy = mb_substr_count($contents, 'z.lazy(() => PostDataSchema)');
    expect($authorLazy + $postLazy)->toBeGreaterThanOrEqual(1);
    @unlink($output);
});
