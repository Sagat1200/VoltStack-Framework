<?php

declare(strict_types=1);

namespace Quantum\Compilation;

use Quantum\Controllers\ControllerDefinition;

final readonly class CompilationResult
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $interceptorDefinitions
     * @param array<string, string> $parameterAliases
     * @param array<string, mixed> $runtimeMetadata
     */
    public function __construct(
        public ControllerDefinition $definition,
        public string $artifactKey,
        public string $class,
        public string $method,
        public string $sourceHash,
        public string $compiledPhpCode,
        public string $compilerVersion,
        public array $interceptorDefinitions = [],
        public array $parameterAliases = [],
        public array $runtimeMetadata = [],
        public bool $success = true,
        public ?\Throwable $error = null,
    ) {}
}
