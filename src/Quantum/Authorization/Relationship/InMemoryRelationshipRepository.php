<?php

declare(strict_types=1);

namespace Quantum\Authorization\Relationship;

use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;

final class InMemoryRelationshipRepository implements RelationshipAdministrationInterface, RelationshipRepositoryInterface
{
    /**
     * @var array<string, array{principal_id:string,relation:string,resource_key:string,scope:string}>
     */
    private array $relationships = [];

    /**
     * @param iterable<array{principal_id:string,relation:string,resource:mixed,scope?:string|Scope|null}> $entries
     */
    public function __construct(
        iterable $entries = [],
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    )
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

            $this->relationships[$this->key($principalId, $relation, $resourceKey, $scopeString)] = [
                'principal_id' => $principalId,
                'relation' => $relation,
                'resource_key' => $resourceKey,
                'scope' => $scopeString,
            ];
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

    public function listRelationships(array $filters = []): array
    {
        $principalId = isset($filters['principal_id']) && is_string($filters['principal_id']) ? trim($filters['principal_id']) : null;
        $relation = isset($filters['relation']) && is_string($filters['relation']) ? trim($filters['relation']) : null;
        $scope = $filters['scope'] ?? null;
        $normalizedScope = $scope === null ? null : $this->normalizeScope($scope instanceof Scope || is_string($scope) ? $scope : null);

        $records = array_values(array_filter(
            $this->relationships,
            static function (array $record) use ($principalId, $relation, $normalizedScope): bool {
                if ($principalId !== null && $principalId !== '' && $record['principal_id'] !== $principalId) {
                    return false;
                }

                if ($relation !== null && $relation !== '' && $record['relation'] !== $relation) {
                    return false;
                }

                if ($normalizedScope !== null && $record['scope'] !== $normalizedScope) {
                    return false;
                }

                return true;
            },
        ));

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
        $key = $this->key(
            trim($principalId),
            trim($relation),
            trim($resourceKey),
            $this->normalizeScope($scope),
        );

        if (! isset($this->relationships[$key])) {
            return false;
        }

        unset($this->relationships[$key]);

        $this->consistency?->invalidateRelationships(trim($principalId), $this->normalizeScope($scope));

        return true;
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
