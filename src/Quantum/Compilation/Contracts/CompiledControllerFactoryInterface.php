<?php

declare(strict_types=1);

namespace Quantum\Compilation\Contracts;

use Quantum\Compilation\CompiledInvocationPlan;
use Quantum\Compilation\ControllerArtifact;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\ResolvedController;

interface CompiledControllerFactoryInterface
{
    public function makeKey(ControllerDefinition $definition): string;

    public function load(string $artifactKey): ?ControllerArtifact;

    public function materialize(ControllerArtifact $artifact, object $container): ResolvedController;

    public function plan(ControllerArtifact $artifact): CompiledInvocationPlan;

    public function isFresh(ControllerArtifact $artifact): bool;

    public function workerCacheHas(string $artifactKey): bool;

    public function workerCacheGet(string $artifactKey): ?ControllerArtifact;

    public function workerCachePut(ControllerArtifact $artifact): void;

    public function workerCacheClear(): int;
}
