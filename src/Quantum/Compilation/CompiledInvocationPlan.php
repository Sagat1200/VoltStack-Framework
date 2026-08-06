<?php

declare(strict_types=1);

namespace Quantum\Compilation;

/**
 * Plan de invocación pre-resuelto desde un artefacto compilado.
 * Evita re-calcular reflection, metadata e interceptores en runtime.
 */
final readonly class CompiledInvocationPlan
{
    /**
     * @param array<int, array{id: string, class: class-string, priority: int, phase: string, arguments: array<string, mixed>}> $interceptors
     * @param array<int, string> $parameterOrder
     * @param array<string, string> $parameterTypeMap
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $interceptors = [],
        public array $parameterOrder = [],
        public array $parameterTypeMap = [],
        public bool $hasContextAwareInterface = false,
        public bool $isInvokable = false,
    ) {}
}
