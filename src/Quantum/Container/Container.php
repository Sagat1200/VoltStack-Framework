<?php

declare(strict_types=1);

namespace Quantum\Container;

use Closure;
use Quantum\Container\Contracts\ContainerInterface;
use Quantum\Container\Exceptions\BindingResolutionException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container implements ContainerInterface
{
    /**
     * @var array<string, Binding>
     */
    protected array $bindings = [];

    /**
     * @var array<string, mixed>
     */
    protected array $instances = [];

    /**
     * @var array<string, mixed>
     */
    protected array $scopedInstances = [];

    /**
     * @var array<string, string>
     */
    protected array $aliases = [];

    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        $abstract = $this->normalize($abstract);
        $concrete ??= $abstract;

        $this->bindings[$abstract] = new Binding($concrete, $shared);
    }

    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function scoped(string $abstract, mixed $concrete = null): void
    {
        $abstract = $this->normalize($abstract);
        $concrete ??= $abstract;

        $this->bindings[$abstract] = new Binding($concrete, false, true);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $abstract = $this->normalize($abstract);
        $this->instances[$abstract] = $instance;
    }

    public function scopedInstance(string $abstract, mixed $instance): void
    {
        $abstract = $this->normalize($abstract);
        $this->scopedInstances[$abstract] = $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->normalize($abstract);

        return isset($this->instances[$abstract])
            || isset($this->scopedInstances[$abstract])
            || isset($this->bindings[$abstract])
            || class_exists($abstract);
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->normalize($abstract);

        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        if (array_key_exists($abstract, $this->scopedInstances)) {
            return $this->scopedInstances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding?->concrete ?? $abstract;

        $object = $this->resolve($concrete, $parameters);

        if ($binding?->shared) {
            $this->instances[$abstract] = $object;
        }

        if ($binding?->scoped) {
            $this->scopedInstances[$abstract] = $object;
        }

        return $object;
    }

    public function flushScope(): void
    {
        $this->scopedInstances = [];
    }

    public function resolved(string $abstract): bool
    {
        $abstract = $this->normalize($abstract);

        return array_key_exists($abstract, $this->instances)
            || array_key_exists($abstract, $this->scopedInstances);
    }

    protected function resolve(mixed $concrete, array $parameters = []): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this, $parameters);
        }

        if (is_string($concrete)) {
            return $this->build($concrete, $parameters);
        }

        if (is_object($concrete)) {
            return $concrete;
        }

        throw new BindingResolutionException('Unable to resolve the given binding.');
    }

    protected function build(string $concrete, array $parameters = []): object
    {
        if (! class_exists($concrete)) {
            throw new BindingResolutionException(sprintf('Target class [%s] does not exist.', $concrete));
        }

        $reflector = new ReflectionClass($concrete);

        if (! $reflector->isInstantiable()) {
            throw new BindingResolutionException(sprintf('Target [%s] is not instantiable.', $concrete));
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $concrete();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $parameter) {
            $dependencies[] = $this->resolveParameter($parameter, $parameters);
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    protected function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        if (array_key_exists($parameter->getName(), $parameters)) {
            return $parameters[$parameter->getName()];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            $typeName = $type->getName();

            if (array_key_exists($typeName, $parameters)) {
                return $parameters[$typeName];
            }

            return $this->make($typeName);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new BindingResolutionException(sprintf(
            'Unable to resolve dependency [%s] in class [%s].',
            $parameter->getName(),
            (string) $parameter->getDeclaringClass()?->getName(),
        ));
    }

    protected function normalize(string $abstract): string
    {
        while (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
