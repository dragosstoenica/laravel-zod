<?php

declare(strict_types=1);

namespace LaravelZod;

use Illuminate\Support\ServiceProvider;
use LaravelZod\Console\GenerateZodSchemasCommand;
use LaravelZod\Discovery\ClassDiscoverer;
use Override;

final class LaravelZodServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-zod.php', 'laravel-zod');

        $this->app->singleton(ClassDiscoverer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/laravel-zod.php' => config_path('laravel-zod.php')],
                'laravel-zod-config',
            );

            $this->commands([
                GenerateZodSchemasCommand::class,
            ]);
        }
    }
}
