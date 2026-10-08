<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

use FilesystemIterator;
use Quantum\Compilation\Build;
use Quantum\Compilation\Contracts\BuildManifestInterface;
use Quantum\Compilation\Exceptions\BuildActivationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class RuntimeBudgetCalibrationStore
{
    public function __construct(
        private readonly BuildManifestInterface $manifest,
        private readonly string $storageRoot,
    ) {
        if (trim($this->storageRoot) === '') {
            throw new RuntimeException('Runtime budget calibration storage root cannot be empty.');
        }
    }

    public function rootPath(): string
    {
        return rtrim($this->storageRoot, '\\/');
    }

    public function buildsPath(): string
    {
        return $this->rootPath() . DIRECTORY_SEPARATOR . 'builds';
    }

    public function currentPath(): string
    {
        return $this->rootPath() . DIRECTORY_SEPARATOR . 'current';
    }

    public function createGeneration(): Build
    {
        $this->ensureDirectories();
        $build = $this->manifest->create();
        $this->ensureDirectory($this->buildDir($build->id));

        return $build;
    }

    public function publish(RuntimeBudgetCalibrationReport $report, ?string $generationId = null): RuntimeBudgetCalibrationArtifact
    {
        $this->ensureDirectories();
        $build = $generationId === null ? $this->createGeneration() : $this->manifest->get($generationId);

        if ($build === null) {
            throw new BuildActivationException(sprintf(
                'Cannot publish runtime budget calibration: generation [%s] does not exist.',
                $generationId,
            ));
        }

        $buildDir = $this->buildDir($build->id);
        $this->ensureDirectory($buildDir);

        $payload = [
            'schema' => 1,
            'generated_at' => date('c'),
            'generation_id' => $build->id,
            'driver' => $report->driver(),
            'profile' => $report->profile(),
            'fingerprint' => $this->fingerprint($report),
            'report' => $report->toArray(),
        ];

        $manifestPath = $buildDir . DIRECTORY_SEPARATOR . 'runtime-budget.calibration.php';
        $tempPath = $manifestPath . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = "<?php\n\nreturn " . var_export($payload, true) . ";\n";

        $written = @file_put_contents($tempPath, $encoded, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf(
                'Failed to write runtime budget calibration temp artifact [%s].',
                $tempPath,
            ));
        }

        if (! @rename($tempPath, $manifestPath)) {
            @unlink($tempPath);

            throw new RuntimeException(sprintf(
                'Failed to publish runtime budget calibration [%s].',
                $manifestPath,
            ));
        }

        @chmod($manifestPath, 0666 & ~umask());

        return new RuntimeBudgetCalibrationArtifact(
            generationId: $build->id,
            driver: $report->driver(),
            profile: $report->profile(),
            fingerprint: (string) $payload['fingerprint'],
            manifestPath: $manifestPath,
            payload: $payload,
            createdAt: $build->createdAt,
        );
    }

    public function activateGeneration(string $generationId): Build
    {
        $build = $this->manifest->get($generationId);

        if ($build === null) {
            throw new BuildActivationException(sprintf(
                'Runtime budget calibration generation [%s] does not exist.',
                $generationId,
            ));
        }

        $buildDir = $this->buildDir($generationId);
        $manifestFile = $buildDir . DIRECTORY_SEPARATOR . 'runtime-budget.calibration.php';

        if (! is_file($manifestFile)) {
            throw new BuildActivationException(sprintf(
                'Runtime budget calibration generation [%s] has no manifest file at [%s].',
                $generationId,
                $manifestFile,
            ));
        }

        $currentPath = $this->currentPath();
        $stagingPath = $this->rootPath() . DIRECTORY_SEPARATOR . 'current.staging.' . bin2hex(random_bytes(4));

        $linked = @symlink($buildDir, $stagingPath);

        if (! $linked) {
            $this->copyDirectory($buildDir, $stagingPath);
        }

        if (! is_dir($stagingPath)) {
            throw new BuildActivationException(sprintf(
                'Failed to prepare runtime budget calibration staging generation [%s].',
                $stagingPath,
            ));
        }

        $lockPath = $this->rootPath() . DIRECTORY_SEPARATOR . 'current.lock';
        $lock = @fopen($lockPath, 'c');

        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }

        try {
            $oldPath = $this->rootPath() . DIRECTORY_SEPARATOR . 'current.old.' . bin2hex(random_bytes(4));

            if (is_dir($currentPath) || is_file($currentPath) || is_link($currentPath)) {
                if (@rename($currentPath, $oldPath)) {
                    $this->deletePath($oldPath);
                } else {
                    $this->deletePath($currentPath);
                }
            }

            if (! @rename($stagingPath, $currentPath)) {
                $this->deletePath($stagingPath);

                throw new BuildActivationException(sprintf(
                    'Failed to activate runtime budget calibration generation [%s].',
                    $generationId,
                ));
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $this->manifest->setCurrent($generationId);
    }

    public function currentArtifact(): ?RuntimeBudgetCalibrationArtifact
    {
        $current = $this->manifest->current();

        if ($current === null) {
            return null;
        }

        $manifestPath = $this->currentPath() . DIRECTORY_SEPARATOR . 'runtime-budget.calibration.php';

        if (! is_file($manifestPath)) {
            $manifestPath = $this->buildDir($current->id) . DIRECTORY_SEPARATOR . 'runtime-budget.calibration.php';
        }

        if (! is_file($manifestPath)) {
            return null;
        }

        /** @psalm-suppress UnresolvableInclude */
        $payload = include $manifestPath;

        if (! is_array($payload)) {
            return null;
        }

        return new RuntimeBudgetCalibrationArtifact(
            generationId: (string) ($payload['generation_id'] ?? $current->id),
            driver: (string) ($payload['driver'] ?? ''),
            profile: (string) ($payload['profile'] ?? 'release'),
            fingerprint: (string) ($payload['fingerprint'] ?? ''),
            manifestPath: $manifestPath,
            payload: $payload,
            createdAt: $current->createdAt,
        );
    }

    public function rollback(): ?Build
    {
        $previous = $this->manifest->previous();

        if ($previous === null) {
            return null;
        }

        return $this->activateGeneration($previous->id);
    }

    private function fingerprint(RuntimeBudgetCalibrationReport $report): string
    {
        $encoded = json_encode(
            $report->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $encoded === false ? '{}' : $encoded);
    }

    private function buildDir(string $generationId): string
    {
        return $this->buildsPath() . DIRECTORY_SEPARATOR . trim($generationId);
    }

    private function ensureDirectories(): void
    {
        $this->ensureDirectory($this->rootPath());
        $this->ensureDirectory($this->buildsPath());
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $created = @mkdir($dir, 0777, true);

        if (! $created && ! is_dir($dir)) {
            throw new RuntimeException(sprintf(
                'Unable to create runtime budget calibration directory [%s].',
                $dir,
            ));
        }
    }

    private function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);
                continue;
            }

            if ($item->isFile()) {
                @copy($item->getPathname(), $targetPath);
            }
        }
    }

    private function deletePath(string $path): int
    {
        if (! is_dir($path) && ! is_file($path) && ! is_link($path)) {
            return 0;
        }

        if (is_link($path) || is_file($path)) {
            $deleted = @unlink($path);

            return $deleted ? 1 : 0;
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $removed = $item->isDir()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());

            if ($removed) {
                $count++;
            }
        }

        if (@rmdir($path)) {
            $count++;
        }

        return $count;
    }
}
