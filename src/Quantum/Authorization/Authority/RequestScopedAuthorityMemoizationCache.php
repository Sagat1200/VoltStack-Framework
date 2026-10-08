<?php

declare(strict_types=1);

namespace Quantum\Authorization\Authority;

use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityMemoizationCacheInterface;

/**
 * Implementación en memoria request-scoped del memoization cache de Authority.
 *
 * Scope lifecycle = instancia scoped del container. Ideal para requests HTTP,
 * comandos CLI, jobs, workers cortos. No compartir entre requests paralelos
 * en runtimes persistentes.
 */
final class RequestScopedAuthorityMemoizationCache implements AuthorityMemoizationCacheInterface
{
    /**
     * @var array<string, list<Permission>>
     */
    private array $cache = [];

    public function __construct(
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    ) {}

    /**
     * @return list<Permission>
     */
    public function rememberEffectivePermissions(
        string   $principalId,
        Scope|string $scope = Scope::GLOBAL,
        callable $compute,
    ): array {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);
        $key = $this->key($principalId, $scopeObject);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $result = $compute();
        $result = is_array($result) ? array_values($result) : [];
        $this->cache[$key] = $result;

        return $result;
    }

    public function has(string $principalId, Scope|string $scope = Scope::GLOBAL): bool
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);

        return array_key_exists($this->key($principalId, $scopeObject), $this->cache);
    }

    public function clear(string $principalId, Scope|string $scope = Scope::GLOBAL): void
    {
        $scopeObject = $scope instanceof Scope ? $scope : new Scope($scope);
        $prefix = $this->tuplePrefix($principalId, $scopeObject);

        foreach (array_keys($this->cache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->cache[$key]);
            }
        }
    }

    public function clearAll(): void
    {
        $this->cache = [];
    }

    private function key(string $principalId, Scope $scope): string
    {
        $version = $this->consistency?->authorityVersion($principalId, $scope) ?? 'v1';

        return $this->tuplePrefix($principalId, $scope) . '|' . $version;
    }

    private function tuplePrefix(string $principalId, Scope $scope): string
    {
        return trim($principalId) . '|' . ((string) $scope);
    }
}
