<?php

declare(strict_types=1);

namespace LaravelZod\Discovery;

use LaravelZod\Attributes\ZodSchema;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

final class ClassDiscoverer
{
    /**
     * @param  list<string>  $paths
     * @return list<ReflectionClass<object>>
     */
    public function discover(array $paths): array
    {
        $classes = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }
            $finder = Finder::create()->in($path)->files()->name('*.php');
            foreach ($finder as $file) {
                $real = $file->getRealPath();
                if ($real === false) {
                    continue;
                }
                $fqn = $this->fqnFromFile($real);
                if ($fqn === null) {
                    continue;
                }
                if (! class_exists($fqn)) {
                    continue;
                }
                $reflection = new ReflectionClass($fqn);
                if ($reflection->getAttributes(ZodSchema::class) === []) {
                    continue;
                }
                $classes[] = $reflection;
            }
        }

        return $classes;
    }

    private function fqnFromFile(string $file): ?string
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $m) === 1) {
            $namespace = mb_trim($m[1]);
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $m) !== 1) {
            return null;
        }

        $class = $m[1];

        return $namespace === '' ? $class : $namespace.'\\'.$class;
    }
}
