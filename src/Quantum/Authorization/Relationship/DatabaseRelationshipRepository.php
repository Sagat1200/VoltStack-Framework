<?php

declare(strict_types=1);

namespace Quantum\Authorization\Relationship;

use PDO;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Database\Contracts\DatabaseInterface;

final class DatabaseRelationshipRepository implements RelationshipAdministrationInterface, RelationshipRepositoryInterface
{
    public const DEFAULT_RELATIONSHIPS_TABLE = 'authorization_relationships';

    private string $table;

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly ?string $connectionName = null,
        ?string $table = null,
        private readonly ?AuthorizationConsistencyInterface $consistency = null,
    ) {
        $this->table = $this->normalizeTableName($table ?? self::DEFAULT_RELATIONSHIPS_TABLE);
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
        $candidateScopes = $this->candidateScopes($scopeObject);
        $placeholders = [];
        $params = [
            ':principal_id' => $normalizedPrincipal,
            ':relation' => $normalizedRelation,
            ':resource_key' => $resourceKey,
        ];

        foreach ($candidateScopes as $index => $candidateScope) {
            $placeholder = ':scope_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $candidateScope;
        }

        $statement = $this->pdo()->prepare(sprintf(
            'SELECT 1 FROM %s WHERE principal_id = :principal_id AND relation = :relation AND resource_key = :resource_key AND scope IN (%s) LIMIT 1',
            $this->table,
            implode(', ', $placeholders),
        ));
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function listRelationships(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        $principalId = isset($filters['principal_id']) && is_string($filters['principal_id']) ? trim($filters['principal_id']) : '';
        if ($principalId !== '') {
            $conditions[] = 'principal_id = :principal_id';
            $params[':principal_id'] = $principalId;
        }

        $relation = isset($filters['relation']) && is_string($filters['relation']) ? trim($filters['relation']) : '';
        if ($relation !== '') {
            $conditions[] = 'relation = :relation';
            $params[':relation'] = $relation;
        }

        $scope = $filters['scope'] ?? null;
        if ($scope instanceof Scope || (is_string($scope) && trim($scope) !== '')) {
            $conditions[] = 'scope = :scope';
            $params[':scope'] = (string) ($scope instanceof Scope ? $scope : new Scope($scope));
        }

        $sql = sprintf(
            'SELECT principal_id, relation, resource_key, scope FROM %s',
            $this->table,
        );

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY principal_id ASC, relation ASC, scope ASC, resource_key ASC';

        /** @var \PDOStatement $statement */
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        /** @var list<array{principal_id:string,relation:string,resource_key:string,scope:string}> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(
            static fn (array $row): array => [
                'principal_id' => (string) ($row['principal_id'] ?? ''),
                'relation' => (string) ($row['relation'] ?? ''),
                'resource_key' => (string) ($row['resource_key'] ?? ''),
                'scope' => (string) ($row['scope'] ?? Scope::GLOBAL),
            ],
            $rows,
        );
    }

    public function revokeRelationshipByKey(
        string $principalId,
        string $relation,
        string $resourceKey,
        Scope|string $scope = Scope::GLOBAL,
    ): bool {
        $statement = $this->pdo()->prepare(sprintf(
            'DELETE FROM %s WHERE principal_id = :principal_id AND relation = :relation AND resource_key = :resource_key AND scope = :scope',
            $this->table,
        ));
        $statement->execute([
            ':principal_id' => trim($principalId),
            ':relation' => trim($relation),
            ':resource_key' => trim($resourceKey),
            ':scope' => (string) ($scope instanceof Scope ? $scope : new Scope($scope)),
        ]);

        $revoked = $statement->rowCount() > 0;

        if ($revoked) {
            $this->consistency?->invalidateRelationships(trim($principalId), $scope instanceof Scope ? $scope : new Scope($scope));
        }

        return $revoked;
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

    private function pdo(): PDO
    {
        return $this->database->connection($this->connectionName)->pdo();
    }

    private function normalizeTableName(string $table): string
    {
        $normalized = trim($table);

        if ($normalized === '' || ! preg_match('/^[A-Za-z0-9_.]+$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid relationship table name.');
        }

        return $normalized;
    }
}
