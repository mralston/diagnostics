<?php

namespace Mralston\Diagnostics\Discovery;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mralston\Diagnostics\Contracts\Check;
use Mralston\Diagnostics\Exceptions\InvalidCheck;
use Mralston\Diagnostics\Suite;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;

/**
 * Finds a suite's checks by scanning its directory as a PSR-4 tree. Each
 * class is instantiated once here to read its metadata; the runner makes a
 * fresh instance for every execution.
 */
class CheckDiscovery
{
    /** @return Collection<int, CheckDefinition> */
    public function discover(Suite $suite): Collection
    {
        $path = $suite->getChecksPath();
        $namespace = $suite->getChecksNamespace();

        if ($path === null || $namespace === null || ! is_dir($path)) {
            return collect();
        }

        $disabled = $suite->disabledChecks();
        $definitions = [];

        foreach ($this->phpFiles($path) as $file) {
            $relative = ltrim(str_replace($path, '', $file->getPathname()), '/\\');
            $class = $namespace.'\\'.str_replace(['/', '\\'], '\\', substr($relative, 0, -4));

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(Check::class)) {
                continue;
            }

            if (in_array($class, $disabled, true)) {
                continue;
            }

            $this->assertRunnable($reflection);

            /** @var Check $check */
            $check = app($class);

            $definitions[] = new CheckDefinition(
                class: $class,
                title: $check->title(),
                description: $check->description(),
                category: $check->category() ?? $this->categoryFromPath($relative),
                canFail: $check->canFail(),
                parallelSafe: $check->isParallelSafe(),
                order: $check->order(),
                timeout: $check->timeout(),
            );
        }

        return $this->sort($definitions, $suite->getCategoryOrder());
    }

    /** @return iterable<SplFileInfo> */
    private function phpFiles(string $path): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        // Deterministic input order so ties in the sort below are stable.
        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strcmp($a->getPathname(), $b->getPathname()));

        return $files;
    }

    private function assertRunnable(ReflectionClass $reflection): void
    {
        if (! $reflection->hasMethod('run')) {
            throw InvalidCheck::missingRun($reflection->getName());
        }

        $method = $reflection->getMethod('run');

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfParameters() < 1) {
            throw InvalidCheck::missingRun($reflection->getName());
        }
    }

    private function categoryFromPath(string $relative): string
    {
        $segments = preg_split('#[/\\\\]#', $relative);

        if (count($segments) < 2) {
            return (string) config('diagnostics.default_category', 'General');
        }

        return Str::headline($segments[0]);
    }

    /**
     * @param  CheckDefinition[]  $definitions
     * @param  string[]  $categoryOrder
     * @return Collection<int, CheckDefinition>
     */
    private function sort(array $definitions, array $categoryOrder): Collection
    {
        $rank = [];
        foreach ($categoryOrder as $i => $category) {
            $rank[Str::lower($category)] = $i;
        }

        usort($definitions, function (CheckDefinition $a, CheckDefinition $b) use ($rank) {
            $ra = $rank[Str::lower($a->category)] ?? PHP_INT_MAX;
            $rb = $rank[Str::lower($b->category)] ?? PHP_INT_MAX;

            return [$ra, Str::lower($a->category), $a->order, Str::lower($a->title)]
                <=> [$rb, Str::lower($b->category), $b->order, Str::lower($b->title)];
        });

        foreach ($definitions as $i => $definition) {
            $definition->position = $i + 1;
        }

        return collect($definitions);
    }
}
