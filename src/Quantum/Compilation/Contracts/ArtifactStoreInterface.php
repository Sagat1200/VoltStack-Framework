<?php

declare(strict_types=1);

namespace Quantum\Compilation\Contracts;

use Quantum\Compilation\Build;
use Quantum\Compilation\ControllerArtifact;
use Quantum\Compilation\CompilationResult;

interface ArtifactStoreInterface
{
    public function rootPath(): string;

    public function buildsPath(): string;

    public function currentPath(): string;

    public function write(CompilationResult $result, ?string $buildId = null): ControllerArtifact;

    public function read(string $artifactKey): ?ControllerArtifact;

    public function exists(string $artifactKey): bool;

    public function validate(ControllerArtifact $artifact): bool;

    public function createBuild(): Build;

    public function activateBuild(string $buildId): Build;

    public function currentBuild(): ?Build;

    public function listBuilds(): array;

    public function pruneStaleBuilds(int $retain = 3): int;

    public function clearBuilds(): int;

    public function rollback(): ?Build;
}
