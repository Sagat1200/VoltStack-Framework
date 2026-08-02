<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors\Contracts;

use Quantum\Controllers\Interceptors\InterceptorDescriptor;

interface ControllerInterceptorRegistryInterface
{
    public function register(InterceptorDescriptor $descriptor): void;

    public function alias(string $alias, string $interceptor): void;

    public function has(string $id): bool;

    public function get(string $id): InterceptorDescriptor;

    public function resolveAlias(string $alias): string;

    public function remove(string $id): void;

    public function replace(string $id, InterceptorDescriptor $replacement): void;

    public function tagged(string $tag): array;

    public function all(): array;

    public function freeze(): void;
}
