<?php

declare(strict_types=1);

namespace Quantum\Authorization\Relationship;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;

final class InMemoryRelationshipRepository implements RelationshipRepositoryInterface
{
    /**
     * @var array<string, true>
     */
    private array $relationships = [];

    /**
     * @param iterable<array{principal_id:string,relation:string,resource:mixed,scope?:string|Scope|null}> $entries
     */
    public function __construct(iterable $entries = [])
    {
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $principalId = isset($entry['principal_id']) && is_string($entry['principal_id']) ? trim($entry['principal_id']) : '';
            $relation = isset($entry['relation']) && is_string($entry['relation']) ? trim($entry['relation']) : '';

            if ($principalId === '' || $relation === '') {
                continue;
            }

            $resourceKey = $this->resourceKey($entry['resource'] ?? null);

            if ($resourceKey === null) {
                continue;
            }

            $scope = $entry['scope'] ?? Scope::GLOBAL;
            $scopeString = $this->normalizeScope($scope);

            $this->relationships[$this->key($principalId, $relation, $resourceKey, $scopeString)] = true;
        }
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
            if (isset($this->relationships[$this->key($normalizedPrincipal, $normalizedRelation, $resourceKey, $candidateScope)])) {
                return true;
            }
        }

        return false;
    }

    private function key(string $principalId, string $relation, string $resourceKey, string $scope): string
    {
        return $principalId . '|' . $relation . '|' . $resourceKey . '|' . $scope;
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

    private function resourceKey(mixed $resource): ?string
    {
        if ($resource === null) {
            return null;
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
}
