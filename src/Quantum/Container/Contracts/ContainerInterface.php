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
    /**
     * Registra un binding transient o shared para un abstract.
     */
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void;

    /**
     * Registra un binding shared por toda la vida del contenedor.
     */
    public function singleton(string $abstract, mixed $concrete = null): void;

    /**
     * Registra un binding cacheado solo durante el scope activo del runtime.
     */
    public function scoped(string $abstract, mixed $concrete = null): void;

    /**
     * Registra una instancia ya construida para un abstract.
     */
    public function instance(string $abstract, mixed $instance): void;

    /**
     * Registra un alias legible para un abstract.
     */
    public function alias(string $abstract, string $alias): void;

    /**
     * Informa si el contenedor puede resolver el abstract sin instanciarlo.
     */
    public function has(string $abstract): bool;

    /**
     * Resuelve un abstract usando overrides de parametros cuando corresponda.
     */
    public function make(string $abstract, array $parameters = []): mixed;

    /**
     * Limpia las instancias cacheadas del scope activo.
     */
    public function flushScope(): void;
}
