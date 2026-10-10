<?php

declare(strict_types=1);

namespace Quantum\Authorization\Relationship;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Cache\Contracts\StoreInterface;

/**
 * Remote-cache backed relationship repository. Relationship entries are
 * stored as a registry + per-tuple markers inside a shared {@see StoreInterface},
 * so multiple workers/nodes observe the same ReBAC state and can invalidate
 * via a shared {@see AuthorizationConsistencyInterface}.
 *
 * Intended as a concrete, non-DBAL sample adapter for {@see RelationshipRepositoryInterface}
 * + {@see RelationshipAdministrationInterface} registered through
 * {@see \Quantum\Authorization\AuthorizationDriverRegistry::extendRelationships()}.
 */
final class RemoteCacheRelationshipRepository implements RelationshipAdministrationInterface, RelationshipRepositoryInterface
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly string $prefix = 'authorization.remote.relationships',
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    ) {
        $prefix = trim($this->prefix);

        if ($prefix === '') {
            throw new \InvalidArgumentException('RemoteCacheRelationshipRepository prefix cannot be empty.');
        }
    }

    public function store(): StoreInterface
    {
        return $this->store;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hasRelationship(
        string $principalId,
        string $relation,
        mixed $resource,
        Scope|string $scope = Scope::GLOBAL,
    ): bool {
        $normalizedPrincipal = trim($principalId);
        $normalizedRelation = trim($relation);
        $resourceKey = $this->resourceKey($resource);

        if ($normalizedPrincipal === '' || $normalizedRelation === '' || $resourceKey === null) {
            return false;
        }

        $scopeObject = $scope instanceof Scope ? $scope : new Scope((string) $scope);

        foreach ($this->candidateScopes($scopeObject) as $candidateScope) {
            $entryKey = $this->entryKey($normalizedPrincipal, $normalizedRelation, $resourceKey, $candidateScope);

            if ($this->store->has($entryKey)) {
                return true;
            }
        }

        return false;
    }

    public function listRelationships(array $filters = []): array
    {
        $principalFilter = isset($filters['principal_id']) && is_string($filters['principal_id'])
            ? trim($filters['principal_id'])
            : null;
        $relationFilter = isset($filters['relation']) && is_string($filters['relation'])
            ? trim($filters['relation'])
            : null;
        $scopeFilter = isset($filters['scope']) ? $this->nullableScopeName($filters['scope']) : null;

        $entries = $this->allEntries();
        $records = [];

        foreach ($entries as $entry) {
            if ($principalFilter !== null && $principalFilter !== '' && $entry['principal_id'] !== $principalFilter) {
                continue;
            }

            if ($relationFilter !== null && $relationFilter !== '' && $entry['relation'] !== $relationFilter) {
                continue;
            }

            if ($scopeFilter !== null && $entry['scope'] !== $scopeFilter) {
                continue;
            }

            $records[] = $entry;
        }

        usort($records, static function (array $left, array $right): int {
            return [$left['principal_id'], $left['relation'], $left['scope'], $left['resource_key']]
                <=> [$right['principal_id'], $right['relation'], $right['scope'], $right['resource_key']];
        });

        return $records;
    }

    public function revokeRelationshipByKey(
        string $principalId,
        string $relation,
        string $resourceKey,
        Scope|string $scope = Scope::GLOBAL,
    ): bool {
        $normalizedPrincipal = trim($principalId);
        $normalizedRelation = trim($relation);
        $normalizedResourceKey = trim($resourceKey);
        $scopeName = $this->normalizeScope($scope);

        if ($normalizedPrincipal === '' || $normalizedRelation === '' || $normalizedResourceKey === '') {
            return false;
        }

        $entryKey = $this->entryKey($normalizedPrincipal, $normalizedRelation, $normalizedResourceKey, $scopeName);

        if (! $this->store->has($entryKey)) {
            return false;
        }

        $this->store->forget($entryKey);
        $this->removeFromRegistry($normalizedPrincipal, $normalizedRelation, $normalizedResourceKey, $scopeName);
        $this->consistency?->invalidateRelationships($normalizedPrincipal, $scopeName, 'relationships.revoke');

        return true;
    }

    /**
     * Seed or upsert a single relationship tuple. Intended for tests and
     * administration helper scripts; the public administration surface keeps
     * {@see RelationshipAdministrationInterface} exactly as-is (revoke + list).
     */
    public function storeRelationship(
        string $principalId,
        string $relation,
        mixed $resource,
        Scope|string $scope = Scope::GLOBAL,
    ): bool {
        $normalizedPrincipal = trim($principalId);
        $normalizedRelation = trim($relation);
        $resourceKey = $this->resourceKey($resource);
        $scopeName = $this->normalizeScope($scope);

        if ($normalizedPrincipal === '' || $normalizedRelation === '' || $resourceKey === null) {
            return false;
        }

        $entryKey = $this->entryKey($normalizedPrincipal, $normalizedRelation, $resourceKey, $scopeName);

        if ($this->store->has($entryKey)) {
            return false;
        }

        $payload = [
            'principal_id' => $normalizedPrincipal,
            'relation' => $normalizedRelation,
            'resource_key' => $resourceKey,
            'scope' => $scopeName,
            'created_at' => date('c'),
        ];

        $written = $this->store->forever($entryKey, $payload);

        if (! $written) {
            throw new \RuntimeException(sprintf(
                'Unable to persist remote relationship entry for principal [%s].',
                $normalizedPrincipal,
            ));
        }

        $this->appendToRegistry($normalizedPrincipal, $normalizedRelation, $resourceKey, $scopeName);
        $this->consistency?->invalidateRelationships($normalizedPrincipal, $scopeName, 'relationships.store');

        return true;
    }

    /**
     * @return list<array{principal_id:string,relation:string,resource_key:string,scope:string}>
     */
    private function allEntries(): array
    {
        $registryRaw = $this->store->get($this->registryKey());
        $registry = is_array($registryRaw) ? $registryRaw : [];
        $entries = [];

        foreach ($registry as $tuple) {
            if (! is_string($tuple) || trim($tuple) === '') {
                continue;
            }

            $parts = explode('|', $tuple, 4);

            if (! isset($parts[3])) {
                continue;
            }

            [$principalId, $relation, $resourceKey, $scopeName] = $parts;
            $entryKey = $this->entryKey($principalId, $relation, $resourceKey, $scopeName);
            $payload = $this->store->get($entryKey);

            if (! is_array($payload)) {
                continue;
            }

            $entries[] = [
                'principal_id' => (string) ($payload['principal_id'] ?? $principalId),
                'relation' => (string) ($payload['relation'] ?? $relation),
                'resource_key' => (string) ($payload['resource_key'] ?? $resourceKey),
                'scope' => (string) ($payload['scope'] ?? $scopeName),
            ];
        }

        return $entries;
    }

    private function appendToRegistry(
        string $principalId,
        string $relation,
        string $resourceKey,
        string $scopeName,
    ): void {
        $key = $this->registryKey();
        $registry = $this->store->get($key);
        $registry = is_array($registry) ? $registry : [];
        $tuple = $principalId . '|' . $relation . '|' . $resourceKey . '|' . $scopeName;
        $registry[$tuple] = $tuple;
        $this->store->forever($key, array_values($registry));
    }

    private function removeFromRegistry(
        string $principalId,
        string $relation,
        string $resourceKey,
        string $scopeName,
    ): void {
        $key = $this->registryKey();
        $registry = $this->store->get($key);
        $registry = is_array($registry) ? $registry : [];
        $tuple = $principalId . '|' . $relation . '|' . $resourceKey . '|' . $scopeName;
        $remaining = [];

        foreach ($registry as $entry) {
            if (! is_string($entry) || $entry === $tuple) {
                continue;
            }

            $remaining[] = $entry;
        }

        $this->store->forever($key, array_values($remaining));
    }

    /**
     * @return list<string>
     */
    private function candidateScopes(Scope $scope): array
    {
        $candidates = [];
        $cursor = $scope;

        while (true) {
            $value = (string) $cursor;

            if (! in_array($value, $candidates, true)) {
                $candidates[] = $value;
            }

            $parent = $cursor->parent();

            if ($parent === null || $parent->equals($cursor)) {
                break;
            }

            $cursor = $parent;
        }

        if (! in_array(Scope::GLOBAL, $candidates, true)) {
            $candidates[] = Scope::GLOBAL;
        }

        return $candidates;
    }

    private function entryKey(string $principalId, string $relation, string $resourceKey, string $scope): string
    {
        return $this->prefix . '.' . sha1($principalId . '|' . $relation . '|' . $resourceKey . '|' . $scope);
    }

    private function registryKey(): string
    {
        return $this->prefix . '.registry';
    }

    private function normalizeScope(Scope|string|null $scope): string
    {
        if ($scope instanceof Scope) {
            return (string) $scope;
        }

        if (! is_string($scope) || trim($scope) === '') {
            return Scope::GLOBAL;
        }

        return (string) new Scope(trim($scope));
    }

    private function nullableScopeName(mixed $scope): ?string
    {
        if ($scope instanceof Scope) {
            return (string) $scope;
        }

        if (is_string($scope) && trim($scope) !== '') {
            return (string) new Scope(trim($scope));
        }

        return null;
    }

    private function resourceKey(mixed $resource): ?string
    {
        if ($resource === null) {
            return null;
        }

        if (is_string($resource)) {
            $trimmed = trim($resource);

            return $trimmed === '' ? null : 'string:' . $trimmed;
        }

        if (is_scalar($resource)) {
            return get_debug_type($resource) . ':' . (string) $resource;
        }

        if (is_object($resource)) {
            if (method_exists($resource, 'id')) {
                $id = $resource->id();
                if (is_scalar($id)) {
                    return get_class($resource) . ':' . (string) $id;
                }
            }

            if (method_exists($resource, 'getId')) {
                $id = $resource->getId();
                if (is_scalar($id)) {
                    return get_class($resource) . ':' . (string) $id;
                }
            }

            if (isset($resource->id) && is_scalar($resource->id)) {
                return get_class($resource) . ':' . (string) $resource->id;
            }
        }

        try {
            return sha1(json_encode($resource, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }
    }
}
