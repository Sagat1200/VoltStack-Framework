<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface;
use Quantum\Controllers\Interceptors\InterceptorDescriptor;
use RuntimeException;

final class ControllerInterceptorRegistry implements ControllerInterceptorRegistryInterface
{
    private array $descriptors = [];

    private array $aliases = [];

    private bool $frozen = false;

    public function register(InterceptorDescriptor $descriptor): void
    {
        $this->ensureNotFrozen();

        $this->descriptors[$descriptor->id] = $descriptor;
    }

    public function alias(string $alias, string $interceptor): void
    {
        $this->ensureNotFrozen();

        $this->aliases[$alias] = $interceptor;
    }

    public function has(string $id): bool
    {
        return isset($this->descriptors[$id]);
    }

    public function get(string $id): InterceptorDescriptor
    {
        if (! isset($this->descriptors[$id])) {
            throw new RuntimeException(sprintf('Interceptor descriptor [%s] is not registered.', $id));
        }

        return $this->descriptors[$id];
    }

    public function resolveAlias(string $alias): string
    {
        return $this->aliases[$alias] ?? $alias;
    }

    public function remove(string $id): void
    {
        $this->ensureNotFrozen();

        unset($this->descriptors[$id]);
    }

    public function replace(string $id, InterceptorDescriptor $replacement): void
    {
        $this->ensureNotFrozen();

        $this->descriptors[$id] = $replacement;
    }

    public function tagged(string $tag): array
    {
        return array_values(array_filter(
            $this->descriptors,
            static fn(InterceptorDescriptor $descriptor): bool => in_array($tag, $descriptor->tags, true),
        ));
    }

    public function all(): array
    {
        return $this->descriptors;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function ensureNotFrozen(): void
    {
        if ($this->frozen) {
            throw new RuntimeException('Interceptor registry is frozen.');
        }
    }
}
