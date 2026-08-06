<?php

declare(strict_types=1);

namespace Quantum\Compilation;

use Quantum\Compilation\Contracts\CompilerInterface;
use Quantum\Compilation\Exceptions\CompilationException;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Controllers\Exceptions\ControllerMethodNotAllowedException;
use Quantum\Controllers\Exceptions\ControllerMethodNotFoundException;
use Quantum\Controllers\Exceptions\ControllerMethodNotPublicException;
use Quantum\Controllers\Exceptions\InvalidControllerMethodException;
use Quantum\Controllers\Exceptions\UnsupportedControllerActionException;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class Compiler implements CompilerInterface
{
    public const VERSION = '1.0.0';

    public function version(): string
    {
        return self::VERSION;
    }

    public function compileDefinition(
        ControllerDefinition $definition,
        ControllerMetadataResolverInterface $metadata,
    ): CompilationResult {
        try {
            [$class, $method] = $this->normalizeAction($definition->action());

            $reflection = new ReflectionClass($class);
            $methodReflection = $this->resolveMethod($reflection, $method);

            $controllerDefinitionForMeta = new ControllerDefinition($class . '@' . $method);
            $fakeContext = new \Quantum\Controllers\Execution\ControllerExecution(
                $controllerDefinitionForMeta,
                new \Quantum\Controllers\ControllerContext(
                    \VoltStack\Framework\Application::getInstance() ?? new \VoltStack\Framework\Application(sys_get_temp_dir()),
                    new \Quantum\Routing\RouteMatch(
                        new \Quantum\Routing\Route('/', $controllerDefinitionForMeta->action()),
                        [],
                    ),
                    new \Quantum\Http\Request([], [], [], [], []),
                ),
                new \Quantum\Controllers\ResolvedController($reflection->newInstanceWithoutConstructor(), $method),
                [],
                new \Quantum\Controllers\ControllerExecutionContext(
                    new \Quantum\Http\Request([], [], [], [], []),
                    new \Quantum\Routing\RouteMatch(
                        new \Quantum\Routing\Route('/', $controllerDefinitionForMeta->action()),
                        [],
                    ),
                ),
            );

            $bag = $metadata->resolve($fakeContextForMeta ?? $fakeContext)->bag();

            $interceptors = $bag->get('controller.interceptors', []);
            $parameterAliases = $bag->get('parameter_aliases', []);
            $runtimeMetadata = [
                'lifecycle_mode' => $bag->get('controller.lifecycle.mode', 'auto'),
                'timeouts_enabled' => $bag->get('controller.lifecycle.timeouts.enabled', true),
                'timeouts_default' => $bag->get('controller.lifecycle.timeouts.default'),
                'compilation_enabled' => $bag->get('controller.compilation.enabled', false),
                'artifacts_format' => $bag->get('controller.compilation.artifacts.format', 'php'),
            ];

            $parameterOrder = [];
            $parameterTypeMap = [];

            foreach ($methodReflection->getParameters() as $param) {
                $parameterOrder[] = $param->getName();
                $type = $param->getType();
                $parameterTypeMap[$param->getName()] = $type !== null ? $type->getName() : '';
            }

            $hasContextAware = $reflection->implementsInterface(
                \Quantum\Controllers\Contracts\ControllerExecutionContextAwareInterface::class,
            );

            $isInvokable = $method === '__invoke';

            $sourceHash = $this->computeSourceHash($reflection, $methodReflection);

            $phpCode = $this->generateArtifactPhp(
                class: $reflection->getName(),
                method: $method,
                interceptorDefinitions: is_array($interceptors) ? $interceptors : [],
                parameterAliases: is_array($parameterAliases) ? $parameterAliases : [],
                parameterOrder: $parameterOrder,
                parameterTypeMap: $parameterTypeMap,
                runtimeMetadata: $runtimeMetadata,
                hasContextAwareInterface: $hasContextAware,
                isInvokable: $isInvokable,
                sourceHash: $sourceHash,
                compilerVersion: self::VERSION,
            );

            $artifactKey = $this->makeArtifactKey($reflection->getName(), $method);

            return new CompilationResult(
                definition: $definition,
                artifactKey: $artifactKey,
                class: $reflection->getName(),
                method: $method,
                sourceHash: $sourceHash,
                compiledPhpCode: $phpCode,
                compilerVersion: self::VERSION,
                interceptorDefinitions: is_array($interceptors) ? $interceptors : [],
                parameterAliases: is_array($parameterAliases) ? $parameterAliases : [],
                runtimeMetadata: $runtimeMetadata,
                success: true,
                error: null,
            );
        } catch (Throwable $e) {
            if (
                $e instanceof UnsupportedControllerActionException
                || $e instanceof InvalidControllerMethodException
                || $e instanceof ControllerMethodNotAllowedException
                || $e instanceof ControllerMethodNotFoundException
                || $e instanceof ControllerMethodNotPublicException
                || $e instanceof CompilationException
            ) {
                throw $e;
            }

            return new CompilationResult(
                definition: $definition,
                artifactKey: $this->fallbackKey($definition),
                class: '',
                method: '',
                sourceHash: '',
                compiledPhpCode: '',
                compilerVersion: self::VERSION,
                interceptorDefinitions: [],
                parameterAliases: [],
                runtimeMetadata: [],
                success: false,
                error: $e,
            );
        }
    }

    public function compileBatch(iterable $controllers, ControllerMetadataResolverInterface $metadata): iterable
    {
        foreach ($controllers as $spec) {
            $class = $spec['class'];
            $method = $spec['method'] ?? '__invoke';
            $action = $method === '__invoke' ? $class : $class . '@' . $method;
            $definition = new ControllerDefinition($action);

            yield $this->compileDefinition($definition, $metadata);
        }
    }

    /**
     * @return array{class-string, string}
     */
    private function normalizeAction(mixed $action): array
    {
        if (is_array($action) && count($action) === 2) {
            [$class, $method] = $action;
            $classString = is_object($class) ? $class::class : (string) $class;

            return [$this->ensureClassString($classString), (string) $method];
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);

            return [$this->ensureClassString($class), (string) $method];
        }

        if (is_string($action) && class_exists($action)) {
            return [$this->ensureClassString($action), '__invoke'];
        }

        throw new UnsupportedControllerActionException();
    }

    /**
     * @param class-string $class
     * @return class-string
     */
    private function ensureClassString(string $class): string
    {
        if (! class_exists($class)) {
            throw new CompilationException(sprintf('Controller class [%s] does not exist.', $class));
        }

        return $class;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function resolveMethod(ReflectionClass $class, string $method): ReflectionMethod
    {
        $normalized = trim($method);

        if ($normalized === '') {
            throw new InvalidControllerMethodException();
        }

        if (str_starts_with($normalized, '__') && $normalized !== '__invoke') {
            throw new ControllerMethodNotAllowedException();
        }

        if (! $class->hasMethod($normalized)) {
            throw new ControllerMethodNotFoundException($normalized);
        }

        $reflection = $class->getMethod($normalized);

        if (! $reflection->isPublic()) {
            throw new ControllerMethodNotPublicException();
        }

        return $reflection;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function computeSourceHash(ReflectionClass $class, ReflectionMethod $method): string
    {
        $filename = $class->getFileName();

        if (! is_string($filename) || ! is_file($filename)) {
            $data = $class->getName() . '::' . $method->getName() . '|' . self::VERSION;

            return hash('sha256', $data);
        }

        $mtime = filemtime($filename);
        $size = filesize($filename);
        $signature = $class->getName() . '::' . $method->getName()
            . '|file:' . $filename
            . '|mtime:' . ($mtime ?: 0)
            . '|size:' . ($size ?: 0)
            . '|compiler:' . self::VERSION;

        return hash('sha256', $signature);
    }

    public function makeArtifactKey(string $class, string $method): string
    {
        return hash('sha256', ltrim($class, '\\') . '::' . $method . '|' . self::VERSION);
    }

    private function fallbackKey(ControllerDefinition $definition): string
    {
        $action = $definition->action();

        if (is_string($action)) {
            return hash('sha256', $action . '|fallback|' . self::VERSION);
        }

        if (is_array($action)) {
            return hash('sha256', json_encode($action, JSON_THROW_ON_ERROR) . '|fallback|' . self::VERSION);
        }

        return hash('sha256', spl_object_hash($definition) . '|fallback|' . self::VERSION);
    }

    /**
     * @param array<string, mixed> $interceptorDefinitions
     * @param array<string, string> $parameterAliases
     * @param array<int, string> $parameterOrder
     * @param array<string, string> $parameterTypeMap
     * @param array<string, mixed> $runtimeMetadata
     */
    private function generateArtifactPhp(
        string $class,
        string $method,
        array $interceptorDefinitions,
        array $parameterAliases,
        array $parameterOrder,
        array $parameterTypeMap,
        array $runtimeMetadata,
        bool $hasContextAwareInterface,
        bool $isInvokable,
        string $sourceHash,
        string $compilerVersion,
    ): string {
        $generatedAt = date('c');
        $exported = var_export([
            'schema' => 1,
            'generated_at' => $generatedAt,
            'compiler_version' => $compilerVersion,
            'source_hash' => $sourceHash,
            'class' => $class,
            'method' => $method,
            'is_invokable' => $isInvokable,
            'has_context_aware_interface' => $hasContextAwareInterface,
            'interceptors' => $interceptorDefinitions,
            'parameter_aliases' => $parameterAliases,
            'parameter_order' => $parameterOrder,
            'parameter_type_map' => $parameterTypeMap,
            'runtime_metadata' => $runtimeMetadata,
            'checksum' => hash('sha256', $class . '::' . $method . '|' . $sourceHash . '|' . $compilerVersion),
        ], true);

        return <<<PHP
<?php

declare(strict_types=1);

/*
 * VoltStack Compiled Controller Artifact
 *
 * Class: {$class}
 * Method: {$method}
 * Generated: {$generatedAt}
 * Compiler Version: {$compilerVersion}
 * Source Hash: {$sourceHash}
 *
 * WARNING: DO NOT EDIT THIS FILE BY HAND.
 * This file was automatically generated by the VoltStack Compiler.
 * Regenerate it by running: php volt compile
 */

return {$exported};
PHP;
    }
}