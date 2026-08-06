<?php

declare(strict_types=1);

namespace Quantum\Compilation;

final readonly class ControllerArtifact
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $interceptorDefinitions
     * @param array<string, string> $parameterAliases
     * @param array<string, mixed> $runtimeMetadata
     */
    public function __construct(
        public string $key,
        public string $class,
        public string $method,
        public string $buildId,
        public int $compiledAt,
        public string $sourceHash,
        public string $compilerVersion,
        public string $artifactPath,
        public array $interceptorDefinitions = [],
        public array $parameterAliases = [],
        public array $runtimeMetadata = [],
        public int $executionPin = 0,
    ) {}

    public function isClass(string $candidate): bool
    {
        return ltrim($this->class, '\\') === ltrim($candidate, '\\');
    }
}
