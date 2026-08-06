<?php

declare(strict_types=1);

namespace Quantum\Compilation;

use FilesystemIterator;
use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\BuildManifestInterface;
use Quantum\Compilation\Exceptions\ArtifactCorruptedException;
use Quantum\Compilation\Exceptions\ArtifactNotFoundException;
use Quantum\Compilation\Exceptions\BuildActivationException;
use Quantum\Compilation\Exceptions\CompilationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class ArtifactStore implements ArtifactStoreInterface
{
    public function __construct(
        private readonly BuildManifestInterface $manifest,
        private readonly string $storageRoot,
        private readonly string $format = 'php',
    ) {
        if (trim($this->storageRoot) === '') {
            throw new CompilationException('Artifact storage root path cannot be empty.');
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

    public function write(CompilationResult $result, ?string $buildId = null): ControllerArtifact
    {
        if (! $result->success) {
            throw new CompilationException(sprintf(
                'Cannot write failed compilation result for key [%s]: %s',
                $result->artifactKey,
                $result->error?->getMessage() ?? 'unknown error',
            ));
        }

        $this->ensureDirectories();

        $actualBuildId = $buildId ?? $this->resolveActiveBuildId();
        $buildDir = $this->buildDir($actualBuildId);
        $this->ensureDirectory($buildDir);

        $filename = $result->artifactKey . '.' . ltrim($this->format, '.');
        $buildArtifactPath = $buildDir . DIRECTORY_SEPARATOR . $filename;
        $tempPath = $buildArtifactPath . '.tmp.' . bin2hex(random_bytes(4));

        $written = @file_put_contents($tempPath, $result->compiledPhpCode, LOCK_EX);

        if ($written === false) {
            @unlink($tempPath);

            throw new CompilationException(sprintf(
                'Failed to write compilation artifact to temp path [%s].',
                $tempPath,
            ));
        }

        $renamed = @rename($tempPath, $buildArtifactPath);

        if (! $renamed) {
            @unlink($tempPath);

            throw new CompilationException(sprintf(
                'Failed atomically rename compilation artifact to [%s].',
                $buildArtifactPath,
            ));
        }

        @chmod($buildArtifactPath, 0666 & ~umask());

        $artifact = new ControllerArtifact(
            key: $result->artifactKey,
            class: $result->class,
            method: $result->method,
            buildId: $actualBuildId,
            compiledAt: time(),
            sourceHash: $result->sourceHash,
            compilerVersion: $result->compilerVersion,
            artifactPath: $buildArtifactPath,
            interceptorDefinitions: $result->interceptorDefinitions,
            parameterAliases: $result->parameterAliases,
            runtimeMetadata: $result->runtimeMetadata,
        );

        $validate = $this->validate($artifact);

        if (! $validate) {
            @unlink($buildArtifactPath);

            throw new ArtifactCorruptedException(sprintf(
                'Compiled artifact for class [%s]::[%s] failed post-write validation.',
                $result->class,
                $result->method,
            ));
        }

        return $artifact;
    }

    public function read(string $artifactKey): ?ControllerArtifact
    {
        $this->ensureDirectories();

        $activeBuildId = $this->resolveActiveBuildId();

        if ($activeBuildId === null) {
            return null;
        }

        $filename = $artifactKey . '.' . ltrim($this->format, '.');

        $candidates = [
            $this->currentPath() . DIRECTORY_SEPARATOR . $filename,
            $this->buildDir($activeBuildId) . DIRECTORY_SEPARATOR . $filename,
        ];

        foreach ($candidates as $candidate) {
            if (! is_file($candidate) || ! is_readable($candidate)) {
                continue;
            }

            $data = $this->includeSafe($candidate);

            if ($data === null || ! is_array($data) || ! isset($data['schema'])) {
                continue;
            }

            return $this->hydrateArtifact($data, $candidate, $activeBuildId);
        }

        return null;
    }

    public function exists(string $artifactKey): bool
    {
        return $this->read($artifactKey) !== null;
    }

    public function validate(ControllerArtifact $artifact): bool
    {
        if (! is_file($artifact->artifactPath) || ! is_readable($artifact->artifactPath)) {
            return false;
        }

        $data = $this->includeSafe($artifact->artifactPath);

        if ($data === null || ! is_array($data)) {
            return false;
        }

        if (! isset($data['checksum'], $data['class'], $data['method'], $data['source_hash'], $data['compiler_version'])) {
            return false;
        }

        $expected = hash(
            'sha256',
            $data['class'] . '::' . $data['method'] . '|' . $data['source_hash'] . '|' . $data['compiler_version'],
        );

        if (! hash_equals($expected, (string) $data['checksum'])) {
            return false;
        }

        return true;
    }

    public function createBuild(): Build
    {
        $this->ensureDirectories();
        $build = $this->manifest->create();
        $this->ensureDirectory($this->buildDir($build->id));

        return $build;
    }

    public function activateBuild(string $buildId): Build
    {
        $build = $this->manifest->get($buildId);

        if ($build === null) {
            throw new BuildActivationException(sprintf('Build [%s] does not exist.', $buildId));
        }

        $buildDir = $this->buildDir($buildId);

        if (! is_dir($buildDir)) {
            throw new BuildActivationException(sprintf('Build directory [%s] does not exist.', $buildDir));
        }

        $currentPath = $this->currentPath();
        $stagingPath = $this->rootPath() . DIRECTORY_SEPARATOR . 'current.staging.' . bin2hex(random_bytes(4));

        if (is_dir($currentPath) || is_link($currentPath)) {
            $linked = @readlink($currentPath);
            if ($linked !== false) {
                $alreadyLinked = rtrim((string) $linked, '\\/') === rtrim($buildDir, '\\/');
                if ($alreadyLinked) {
                    return $this->manifest->setCurrent($buildId);
                }
            }
        }

        $linked = @symlink($buildDir, $stagingPath);

        if (! $linked) {
            $this->copyDirectory($buildDir, $stagingPath);
        }

        if (! is_dir($stagingPath)) {
            @rmdir($stagingPath);

            throw new BuildActivationException(sprintf(
                'Failed to prepare staging build at [%s].',
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

            if (is_dir($currentPath) || is_link($currentPath) || is_file($currentPath)) {
                $renamedOld = @rename($currentPath, $oldPath);
                if ($renamedOld) {
                    $this->deletePath($oldPath);
                } else {
                    $this->deletePath($currentPath);
                }
            }

            $activated = @rename($stagingPath, $currentPath);

            if (! $activated) {
                $this->deletePath($stagingPath);

                throw new BuildActivationException(sprintf(
                    'Failed to atomically activate build [%s] -> [%s].',
                    $stagingPath,
                    $currentPath,
                ));
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $this->manifest->setCurrent($buildId);
    }

    public function currentBuild(): ?Build
    {
        return $this->manifest->current();
    }

    public function listBuilds(): array
    {
        return $this->manifest->all();
    }

    public function pruneStaleBuilds(int $retain = 3): int
    {
        $retained = $this->manifest->prune(max(1, $retain));
        $existingIds = array_map(static fn(Build $b) => $b->id, $retained);

        $buildsPath = $this->buildsPath();

        if (! is_dir($buildsPath)) {
            return 0;
        }

        $deleted = 0;

        foreach (glob($buildsPath . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirId = basename($dir);

            if (in_array($dirId, $existingIds, true)) {
                continue;
            }

            $deletedCount = $this->deletePath($dir);

            if ($deletedCount > 0) {
                $deleted++;
            }
        }

        return $deleted;
    }

    public function clearBuilds(): int
    {
        $this->ensureDirectories();
        $count = $this->deletePath($this->buildsPath()) + $this->deletePath($this->currentPath());

        $manifestPath = $this->manifestPath();
        if (is_file($manifestPath)) {
            @unlink($manifestPath);
            $count++;
        }

        return $count;
    }

    public function rollback(): ?Build
    {
        $previous = $this->manifest->previous();

        if ($previous === null) {
            return null;
        }

        return $this->activateBuild($previous->id);
    }

    private function resolveActiveBuildId(): ?string
    {
        $current = $this->manifest->current();

        if ($current !== null) {
            return $current->id;
        }

        $all = $this->manifest->all();

        if ($all === []) {
            $build = $this->createBuild();
            $this->manifest->setCurrent($build->id);

            return $build->id;
        }

        $latest = $all[0] ?? null;

        if ($latest !== null) {
            $this->manifest->setCurrent($latest->id);

            return $latest->id;
        }

        return null;
    }

    private function buildDir(string $buildId): string
    {
        return $this->buildsPath() . DIRECTORY_SEPARATOR . trim($buildId);
    }

    private function manifestPath(): string
    {
        return $this->rootPath() . DIRECTORY_SEPARATOR . 'builds.manifest.json';
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
            throw new RuntimeException(sprintf('Unable to create directory [%s].', $dir));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function includeSafe(string $path): ?array
    {
        $level = error_reporting(0);

        try {
            /** @psalm-suppress UnresolvableInclude */
            $result = include $path;
        } finally {
            error_reporting($level);
        }

        if (! is_array($result)) {
            return null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateArtifact(array $data, string $path, string $activeBuildId): ControllerArtifact
    {
        return new ControllerArtifact(
            key: basename($path, '.' . ltrim($this->format, '.')),
            class: isset($data['class']) && is_string($data['class']) ? $data['class'] : '',
            method: isset($data['method']) && is_string($data['method']) ? $data['method'] : '',
            buildId: $activeBuildId,
            compiledAt: isset($data['generated_at']) ? (int) strtotime((string) $data['generated_at']) : time(),
            sourceHash: isset($data['source_hash']) && is_string($data['source_hash']) ? $data['source_hash'] : '',
            compilerVersion: isset($data['compiler_version']) && is_string($data['compiler_version']) ? $data['compiler_version'] : '',
            artifactPath: $path,
            interceptorDefinitions: isset($data['interceptors']) && is_array($data['interceptors']) ? $data['interceptors'] : [],
            parameterAliases: isset($data['parameter_aliases']) && is_array($data['parameter_aliases']) ? $data['parameter_aliases'] : [],
            runtimeMetadata: isset($data['runtime_metadata']) && is_array($data['runtime_metadata']) ? $data['runtime_metadata'] : [],
        );
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
            if ($item->isDir()) {
                $removed = @rmdir($item->getPathname());
            } else {
                $removed = @unlink($item->getPathname());
            }

            if ($removed) {
                $count++;
            }
        }

        $removed = @rmdir($path);

        if ($removed) {
            $count++;
        }

        return $count;
    }
}
