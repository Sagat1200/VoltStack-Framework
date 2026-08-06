<?php

declare(strict_types=1);

namespace Quantum\Compilation;

use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
use Quantum\Compilation\Exceptions\CompilationException;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\Exceptions\UnsupportedControllerActionException;
use Quantum\Controllers\ResolvedController;

final class CompiledControllerFactory implements CompiledControllerFactoryInterface
{
    /**
     * @var array<string, ControllerArtifact>
     */
    private array $workerCache = [];

    private int $workerMaxArtifacts;

    private int $currentExecutionPin = 0;

    private ?string $pinnedBuildId = null;

    public function __construct(
        private readonly ArtifactStoreInterface $store,
        private readonly int $maxWorkerArtifacts = 2048,
    ) {
        $this->workerMaxArtifacts = max(16, $maxWorkerArtifacts);
    }

    public function makeKey(ControllerDefinition $definition): string
    {
        [$class, $method] = $this->normalize($definition->action());

        return hash('sha256', ltrim($class, '\\') . '::' . $method . '|' . Compiler::VERSION);
    }

    public function load(string $artifactKey): ?ControllerArtifact
    {
        if ($this->workerCacheHas($artifactKey)) {
            $artifact = $this->workerCacheGet($artifactKey);

            if ($artifact !== null) {
                if ($this->pinnedBuildId === null || $artifact->buildId === $this->pinnedBuildId) {
                    return $artifact;
                }
            }
        }

        $artifact = $this->store->read($artifactKey);

        if ($artifact === null) {
            return null;
        }

        if ($this->pinnedBuildId !== null && $artifact->buildId !== $this->pinnedBuildId) {
            return null;
        }

        if (! $this->isFresh($artifact)) {
            return null;
        }

        $this->workerCachePut($artifact);

        return $artifact;
    }

    public function materialize(ControllerArtifact $artifact, object $container): ResolvedController
    {
        if (! is_string($artifact->class) || trim($artifact->class) === '') {
            throw new CompilationException('Cannot materialize artifact: class is empty.');
        }

        if (! method_exists($container, 'make')) {
            throw new CompilationException('Container for artifact materialization must implement make() method.');
        }

        try {
            $instance = $container->make($artifact->class);
        } catch (\Throwable $e) {
            throw new CompilationException(sprintf(
                'Failed to materialize controller instance [%s]: %s',
                $artifact->class,
                $e->getMessage(),
            ), previous: $e);
        }

        $method = $artifact->method;

        if (trim($method) === '') {
            $method = '__invoke';
        }

        return new ResolvedController($instance, $method);
    }

    public function plan(ControllerArtifact $artifact): CompiledInvocationPlan
    {
        $interceptors = [];

        foreach ($artifact->interceptorDefinitions as $def) {
            if (! is_array($def)) {
                continue;
            }

            $id = isset($def['id']) && is_string($def['id']) ? $def['id'] : uniqid('interceptor_', true);
            $class = isset($def['class']) && is_string($def['class']) ? $def['class'] : '';
            $priority = isset($def['priority']) ? (int) $def['priority'] : 100;
            $phase = isset($def['phase']) && is_string($def['phase']) ? $def['phase'] : 'pre';
            $arguments = isset($def['arguments']) && is_array($def['arguments']) ? $def['arguments'] : [];

            if ($class === '') {
                continue;
            }

            $interceptors[] = [
                'id' => $id,
                'class' => $class,
                'priority' => $priority,
                'phase' => $phase,
                'arguments' => $arguments,
            ];
        }

        $parameterOrder = isset($artifact->runtimeMetadata['parameter_order']) && is_array($artifact->runtimeMetadata['parameter_order'])
            ? $artifact->runtimeMetadata['parameter_order']
            : [];

        $parameterTypeMap = isset($artifact->runtimeMetadata['parameter_type_map']) && is_array($artifact->runtimeMetadata['parameter_type_map'])
            ? $artifact->runtimeMetadata['parameter_type_map']
            : [];

        $hasContextAware = isset($artifact->runtimeMetadata['has_context_aware_interface'])
            ? (bool) $artifact->runtimeMetadata['has_context_aware_interface']
            : false;

        $isInvokable = $artifact->method === '__invoke'
            || (isset($artifact->runtimeMetadata['is_invokable']) && (bool) $artifact->runtimeMetadata['is_invokable']);

        return new CompiledInvocationPlan(
            class: $artifact->class,
            method: $artifact->method,
            interceptors: $interceptors,
            parameterOrder: is_array($parameterOrder) ? array_values($parameterOrder) : [],
            parameterTypeMap: is_array($parameterTypeMap) ? $parameterTypeMap : [],
            hasContextAwareInterface: $hasContextAware,
            isInvokable: $isInvokable,
        );
    }

    public function isFresh(ControllerArtifact $artifact): bool
    {
        if ($artifact->class === '' || $artifact->method === '') {
            return false;
        }

        if ($artifact->sourceHash === '' || $artifact->compilerVersion === '') {
            return false;
        }

        if ($artifact->compilerVersion !== Compiler::VERSION) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($artifact->class);
        } catch (\ReflectionException) {
            return false;
        }

        if (! $reflection->hasMethod($artifact->method)) {
            return false;
        }

        $filename = $reflection->getFileName();

        if (! is_string($filename) || ! is_file($filename)) {
            return $artifact->sourceHash !== '';
        }

        $mtime = filemtime($filename);
        $size = filesize($filename);
        $expected = hash('sha256', $reflection->getName() . '::' . $artifact->method
            . '|file:' . $filename
            . '|mtime:' . ($mtime ?: 0)
            . '|size:' . ($size ?: 0)
            . '|compiler:' . Compiler::VERSION);

        return hash_equals($expected, $artifact->sourceHash);
    }

    public function workerCacheHas(string $artifactKey): bool
    {
        return isset($this->workerCache[$artifactKey]);
    }

    public function workerCacheGet(string $artifactKey): ?ControllerArtifact
    {
        return $this->workerCache[$artifactKey] ?? null;
    }

    public function workerCachePut(ControllerArtifact $artifact): void
    {
        if (isset($this->workerCache[$artifact->key])) {
            unset($this->workerCache[$artifact->key]);
        }

        if (count($this->workerCache) >= $this->workerMaxArtifacts) {
            $removeCount = (int) ceil($this->workerMaxArtifacts * 0.1);

            for ($i = 0; $i < $removeCount; $i++) {
                $firstKey = array_key_first($this->workerCache);

                if ($firstKey === null) {
                    break;
                }

                unset($this->workerCache[$firstKey]);
            }
        }

        $this->workerCache[$artifact->key] = $artifact;
    }

    public function workerCacheClear(): int
    {
        $count = count($this->workerCache);
        $this->workerCache = [];

        return $count;
    }

    public function beginPinnedExecution(): int
    {
        $this->currentExecutionPin++;
        $this->pinnedBuildId = $this->store->currentBuild()?->id;

        return $this->currentExecutionPin;
    }

    public function endPinnedExecution(): void
    {
        if ($this->currentExecutionPin <= 1) {
            $this->pinnedBuildId = null;
            $this->currentExecutionPin = 0;

            return;
        }

        $this->currentExecutionPin--;
    }

    public function currentPinnedBuildId(): ?string
    {
        return $this->pinnedBuildId;
    }

    /**
     * @return array{class-string, string}
     */
    private function normalize(mixed $action): array
    {
        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            $classString = is_object($class) ? $class::class : (string) $class;

            return [ltrim($classString, '\\'), (string) $method];
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return [ltrim($class, '\\'), trim($method)];
        }

        if (is_string($action) && class_exists($action)) {
            return [ltrim($action, '\\'), '__invoke'];
        }

        throw new UnsupportedControllerActionException();
    }
}
