<?php

declare(strict_types=1);

namespace LaravelZod\Tests;

use LaravelZod\LaravelZodServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LaravelZodServiceProvider::class];
    }
}
