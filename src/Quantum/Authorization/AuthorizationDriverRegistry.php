<?php

declare(strict_types=1);

namespace Quantum\Authorization;

use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use VoltStack\Framework\Application;

final class AuthorizationDriverRegistry
{
    /**
     * @var array<string, callable(Application): AuthorityRepositoryInterface>
     */
    private array $authorityDrivers = [];

    /**
     * @var array<string, callable(Application): RelationshipRepositoryInterface>
     */
    private array $relationshipDrivers = [];

    /**
     * @var array<string, callable(Application): AuthorizationConsistencyInterface>
     */
    private array $consistencyDrivers = [];

    /**
     * @param callable(Application): AuthorityRepositoryInterface $factory
     */
    public function extendAuthority(string $driver, callable $factory): void
    {
        $this->authorityDrivers[$this->normalizeDriver($driver)] = $factory;
    }

    /**
     * @param callable(Application): RelationshipRepositoryInterface $factory
     */
    public function extendRelationships(string $driver, callable $factory): void
    {
        $this->relationshipDrivers[$this->normalizeDriver($driver)] = $factory;
    }

    /**
     * @param callable(Application): AuthorizationConsistencyInterface $factory
     */
    public function extendConsistency(string $driver, callable $factory): void
    {
        $this->consistencyDrivers[$this->normalizeDriver($driver)] = $factory;
    }

    public function resolveAuthority(string $driver, Application $app): ?AuthorityRepositoryInterface
    {
        $factory = $this->authorityDrivers[$this->normalizeDriver($driver)] ?? null;

        if ($factory === null) {
            return null;
        }

        $resolved = $factory($app);

        return $resolved instanceof AuthorityRepositoryInterface ? $resolved : null;
    }

    public function resolveRelationships(string $driver, Application $app): ?RelationshipRepositoryInterface
    {
        $factory = $this->relationshipDrivers[$this->normalizeDriver($driver)] ?? null;

        if ($factory === null) {
            return null;
        }

        $resolved = $factory($app);

        return $resolved instanceof RelationshipRepositoryInterface ? $resolved : null;
    }

    public function resolveConsistency(string $driver, Application $app): ?AuthorizationConsistencyInterface
    {
        $factory = $this->consistencyDrivers[$this->normalizeDriver($driver)] ?? null;

        if ($factory === null) {
            return null;
        }

        $resolved = $factory($app);

        return $resolved instanceof AuthorizationConsistencyInterface ? $resolved : null;
    }

    private function normalizeDriver(string $driver): string
    {
        $normalized = strtolower(trim($driver));

        if ($normalized === '') {
            throw new \InvalidArgumentException('Authorization driver name cannot be empty.');
        }

        return $normalized;
    }
}
