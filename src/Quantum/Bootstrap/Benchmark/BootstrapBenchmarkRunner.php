<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Benchmark;

use Quantum\Bootstrap\Budget\BootstrapBudget;
use Quantum\Bootstrap\Release\BootstrapReleaseChecker;

final class BootstrapBenchmarkRunner
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(
        string $profile = 'release',
        ?string $artifactDirectory = null,
        ?BootstrapBudget $budget = null,
        bool $emitPhaseTelemetry = false,
        bool $requirePublishedConfig = false,
    ): BootstrapBenchmarkReport {
        $artifactDirectory = $this->normalizeArtifactDirectory($artifactDirectory);
        $this->deleteDirectory($artifactDirectory);

        $checker = new BootstrapReleaseChecker($this->basePath);
        $cold = $checker->run(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            budget: $budget,
            emitPhaseTelemetry: $emitPhaseTelemetry,
            requirePublishedConfig: $requirePublishedConfig,
        );
        $warm = $checker->run(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            budget: $budget,
            emitPhaseTelemetry: $emitPhaseTelemetry,
            requirePublishedConfig: $requirePublishedConfig,
        );

        return new BootstrapBenchmarkReport(
            profile: $profile,
            artifactDirectory: $artifactDirectory,
            cold: $cold,
            warm: $warm,
        );
    }

    private function normalizeArtifactDirectory(?string $artifactDirectory): string
    {
        $artifactDirectory = trim((string) $artifactDirectory);

        if ($artifactDirectory === '') {
            return $this->basePath
                . DIRECTORY_SEPARATOR . 'storage'
                . DIRECTORY_SEPARATOR . 'framework'
                . DIRECTORY_SEPARATOR . 'bootstrap'
                . DIRECTORY_SEPARATOR . 'benchmark';
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifactDirectory);

        if ($this->isAbsolutePath($normalized)) {
            return rtrim($normalized, '\\/');
        }

        return rtrim($this->basePath . DIRECTORY_SEPARATOR . ltrim($normalized, '\\/'), '\\/');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:\\\\/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}
