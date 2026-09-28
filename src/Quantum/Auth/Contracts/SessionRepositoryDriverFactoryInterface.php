<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface SessionRepositoryDriverFactoryInterface
{
    /**
     * @param string $alias
     * @param callable(array<string, mixed> $config): AuthenticationSessionRepositoryInterface $factory
     */
    public function registerDriver(string $alias, callable $factory): void;

    /**
     * @param array<string, mixed> $config
     */
    public function hasDriver(string $alias): bool;

    /**
     * @param array<string, mixed> $config
     */
    public function make(string $alias, array $config = []): AuthenticationSessionRepositoryInterface;
}
