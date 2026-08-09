<?php

declare(strict_types=1);

namespace Quantum\Container\Contracts;

/**
 * @api Contrato público estable del Service Container (versión BC garantizada hasta 2.x).
 *
 * Cualquier implementación customizada de Container debe cumplir estas firmas para
 * ser compatible con HttpKernel, Controllers, ServiceProviders y Security stack.
 */
interface ContainerInterface
{
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void;

    public function singleton(string $abstract, mixed $concrete = null): void;

    public function scoped(string $abstract, mixed $concrete = null): void;

    public function instance(string $abstract, mixed $instance): void;

    public function alias(string $abstract, string $alias): void;

    public function has(string $abstract): bool;

    public function make(string $abstract, array $parameters = []): mixed;

    public function flushScope(): void;
}
