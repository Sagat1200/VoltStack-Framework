<?php

declare(strict_types=1);

namespace Quantum\Compilation\Contracts;

use Quantum\Compilation\CompilationResult;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;

interface CompilerInterface
{
    public function compileDefinition(
        ControllerDefinition $definition,
        ControllerMetadataResolverInterface $metadata,
    ): CompilationResult;

    /**
     * @param iterable<int, array{class: class-string, method: string|null}> $controllers
     * @return iterable<int, CompilationResult>
     */
    public function compileBatch(iterable $controllers, ControllerMetadataResolverInterface $metadata): iterable;

    public function version(): string;
}