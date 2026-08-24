<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\EntityManager;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Dialect\Support\DialectFactory;
use Quantum\Database\Orm\Hydration\HydrationOptions;
use Quantum\Database\Orm\Hydration\HydratorInterface;
use Quantum\Database\Orm\Hydration\RowToEntityHydrator;
use Quantum\Database\Orm\Mapping\CustomTypeBridgeRegistry;
use Quantum\Database\Orm\Mapping\DefaultPropertyAccessor;
use Quantum\Database\Orm\Mapping\EntityToRowMapper;
use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Metadata\AttributeMetadataLoader;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\MetadataManager;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Repository\DefaultRepository;
use Quantum\Database\Orm\UnitOfWork\ArrayIdentityMap;
use Quantum\Database\Orm\UnitOfWork\Association\AssociationCascadeEngine;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\SnapshotChangeTracker;
use Quantum\Database\Orm\UnitOfWork\Dependency\DependencyGraph;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\DefaultEntityPersister;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\EntityPersisterInterface;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\IdentifierExtractor;
use Quantum\Database\Orm\UnitOfWork\Event\NullLifecycleEventDispatcher;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;
use Quantum\Database\Orm\UnitOfWork\UnitOfWork;
use Quantum\Database\Operation\DatabaseDiagnosticSnapshot;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;
use Quantum\Database\Query\SelectQueryBuilder;

/**
 * EntityManager concreto del ORM V1.
 *
 * La implementación expone helpers adicionales (`getDialect()`, `getHydrator()`,
 * `getIdentityMap()`, `getUnitOfWork()`, `getRepository()`) que hoy consumen
 * loaders y persisters internos aunque todavía no formen parte del interface
 * placeholder.
 */
final class EntityManager implements EntityManagerInterface
{
    /** @var array<class-string,DefaultRepository> */
    private array $repositories = [];

    private readonly MetadataManagerInterface $metadataFactory;
    private readonly PropertyAccessorInterface $accessor;
    private readonly CustomTypeBridgeRegistry $typeBridgeRegistry;
    private readonly HydratorInterface $hydrator;
    private readonly IdentityMapInterface $identityMap;
    private readonly SnapshotChangeTracker $changeTracker;
    private readonly EntityPersisterInterface $persister;
    private readonly UnitOfWork $unitOfWork;
    private readonly DialectInterface $dialect;
    private readonly DatabaseContext $context;
    private readonly ?string $tenantId;
    private readonly ?DatabaseOperationRuntime $operationRuntime;
    private ?DatabaseOperationPlan $lastReadPlan = null;
    private ?DatabaseDiagnosticSnapshot $lastReadDiagnostic = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        ?MetadataManagerInterface $metadataFactory = null,
        ?HydratorInterface $hydrator = null,
        ?UnitOfWork $unitOfWork = null,
        ?DialectInterface $dialect = null,
        ?DatabaseContext $context = null,
        ?string $tenantId = null,
        ?PropertyAccessorInterface $accessor = null,
        ?CustomTypeBridgeRegistry $typeBridgeRegistry = null,
        ?IdentityMapInterface $identityMap = null,
        ?SnapshotChangeTracker $changeTracker = null,
        ?EntityPersisterInterface $persister = null,
        ?DatabaseOperationRuntime $operationRuntime = null,
    ) {
        $this->metadataFactory = $metadataFactory ?? new MetadataManager(
            loader: new AttributeMetadataLoader(),
        );
        $this->accessor = $accessor ?? new DefaultPropertyAccessor();
        $this->typeBridgeRegistry = $typeBridgeRegistry ?? new CustomTypeBridgeRegistry();
        $this->identityMap = $identityMap ?? new ArrayIdentityMap();
        $this->changeTracker = $changeTracker ?? new SnapshotChangeTracker($this->accessor);
        $this->persister = $persister ?? new DefaultEntityPersister(
            new EntityToRowMapper($this->accessor, $this->typeBridgeRegistry),
            $this->accessor,
        );
        $this->tenantId = $tenantId ?? $context?->tenantId;
        $this->operationRuntime = $operationRuntime;
        $this->hydrator = $hydrator ?? new RowToEntityHydrator(
            $this->accessor,
            new IdentifierExtractor($this->accessor),
            $this->typeBridgeRegistry,
            $this->tenantId,
        );
        $this->dialect = $dialect ?? $this->resolveDialect($connection);
        $this->unitOfWork = $unitOfWork ?? new UnitOfWork(
            identityMap: $this->identityMap,
            changeTracker: $this->changeTracker,
            persister: $this->persister,
            cascadeEngine: new AssociationCascadeEngine($this->metadataFactory),
            dependencyGraph: new DependencyGraph(),
            eventDispatcher: new NullLifecycleEventDispatcher(),
        );

        $ctx = $context ?? DatabaseContext::empty();
        $ctx = $ctx->withConnection($connection);
        if ($this->tenantId !== null) {
            $ctx = $ctx->withTenant($this->tenantId);
        }
        $this->context = $ctx;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getMetadataFactory(): MetadataManagerInterface
    {
        return $this->metadataFactory;
    }

    public function persist(object $entity): void
    {
        $this->unitOfWork->persist($entity, $this);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity, $this);
    }

    public function flush(): void
    {
        $this->unitOfWork->flush($this);
    }

    public function clear(): void
    {
        $this->unitOfWork->clear();
        $this->repositories = [];
    }

    public function contains(object $entity): bool
    {
        return $this->unitOfWork->contains($entity);
    }

    public function detach(object $entity): void
    {
        $this->unitOfWork->detach($entity);
    }

    public function find(string $entityClass, mixed $id): ?object
    {
        $meta = $this->metadataFactory->getMetadataFor($entityClass);
        $identifierColumns = $this->normalizeIdentifierColumns($meta, $id);
        $idHash = $this->hashIdentifierValues($identifierColumns);

        if ($this->identityMap->has($meta->entityClass, $idHash)) {
            return $this->identityMap->get($meta->entityClass, $idHash);
        }

        $entity = $this->persister->loadById(
            $identifierColumns,
            $meta,
            $this->connection,
            $this->identityMap,
            $this->hydrator,
            $this->tenantId,
        );

        if ($entity !== null) {
            $this->takeManagedSnapshot($entity, $meta);
        }

        return $entity;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    public function getHydrator(): HydratorInterface
    {
        return $this->hydrator;
    }

    public function getIdentityMap(): IdentityMapInterface
    {
        return $this->identityMap;
    }

    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    public function getContext(): DatabaseContext
    {
        return $this->context;
    }

    public function getRepository(string $entityClass): DefaultRepository
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }

        $meta = $this->metadataFactory->getMetadataFor($entityClass);
        $repoClass = $meta->repositoryClass;

        if (!class_exists($repoClass) || !is_a($repoClass, DefaultRepository::class, true)) {
            $repoClass = DefaultRepository::class;
        }

        /** @var DefaultRepository $repository */
        $repository = new $repoClass($this, $meta, $entityClass);
        $this->repositories[$entityClass] = $repository;

        return $repository;
    }

    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $criteria
     * @param array<string,string>|null $orderBy
     * @return list<object>
     */
    public function findBy(string $entityClass, array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        $meta = $this->metadataFactory->getMetadataFor($entityClass);
        $query = $this->buildSelectQuery($meta, $criteria, $orderBy, $limit, $offset);
        $rows = $this->executeReadQuery($query['sql'], $query['params']);
        $entities = iterator_to_array(
            $this->hydrator->hydrateAll($rows, $meta, $this->identityMap, HydrationOptions::defaults()),
            false,
        );

        foreach ($entities as $entity) {
            if (is_object($entity)) {
                $this->takeManagedSnapshot($entity, $meta);
            }
        }

        /** @var list<object> $entities */
        return $entities;
    }

    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(string $entityClass, array $criteria, ?array $orderBy = null): ?object
    {
        $results = $this->findBy($entityClass, $criteria, $orderBy, 1, null);
        return $results[0] ?? null;
    }

    /**
     * @param class-string $entityClass
     * @return list<object>
     */
    public function findAll(string $entityClass): array
    {
        return $this->findBy($entityClass, []);
    }

    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $criteria
     */
    public function count(string $entityClass, array $criteria = []): int
    {
        $meta = $this->metadataFactory->getMetadataFor($entityClass);
        $query = $this->buildCountQuery($meta, $criteria);
        $row = $this->executeReadQuery($query['sql'], $query['params'])->fetchOneAssoc();

        if ($row === null) {
            return 0;
        }

        $value = $row['aggregate_count'] ?? array_values($row)[0] ?? 0;
        return (int) $value;
    }

    /**
     * @param class-string $entityClass
     */
    public function createQueryBuilder(string $entityClass, string $alias): SelectQueryBuilder
    {
        $meta = $this->metadataFactory->getMetadataFor($entityClass);

        return (new SelectQueryBuilder($this->connection, $this->context, $this->operationRuntime))
            ->from($this->tableIdentifier($meta), $alias);
    }

    public function getLastReadPlan(): ?DatabaseOperationPlan
    {
        return $this->lastReadPlan;
    }

    public function getLastReadDiagnostic(): ?DatabaseDiagnosticSnapshot
    {
        return $this->lastReadDiagnostic;
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    private function buildSelectQuery(
        CompiledEntityMetadata $meta,
        array $criteria,
        ?array $orderBy,
        ?int $limit,
        ?int $offset,
    ): array {
        ['where' => $where, 'params' => $params] = $this->buildWhereClause($meta, $criteria);

        $sql = 'SELECT * FROM ' . $this->connection->quoteIdentifier($this->tableIdentifier($meta));
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        if ($orderBy !== null && $orderBy !== []) {
            $parts = [];
            foreach ($orderBy as $field => $direction) {
                $parts[] = $this->connection->quoteIdentifier($this->resolveColumnName($meta, $field))
                    . ' '
                    . $this->normalizeOrderDirection($direction);
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . (int) $offset;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @return array{sql:string,params:list<mixed>}
     */
    private function buildCountQuery(CompiledEntityMetadata $meta, array $criteria): array
    {
        ['where' => $where, 'params' => $params] = $this->buildWhereClause($meta, $criteria);

        $sql = 'SELECT COUNT(*) AS aggregate_count FROM '
            . $this->connection->quoteIdentifier($this->tableIdentifier($meta));

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param array<string,mixed> $criteria
     * @return array{where:string,params:list<mixed>}
     */
    private function buildWhereClause(CompiledEntityMetadata $meta, array $criteria): array
    {
        $parts = [];
        $params = [];

        foreach ($criteria as $field => $value) {
            if (isset($meta->properties[$field])) {
                $column = $meta->properties[$field]->columnName;
            } elseif (isset($meta->columnToPropertyMap[$field])) {
                $column = $field;
            } elseif (isset($meta->associations[$field])) {
                $assoc = $meta->associations[$field];
                if (!$assoc->isOwningSide || $assoc->joinColumnName === null) {
                    throw new \InvalidArgumentException("La asociación [{$field}] no puede usarse como criterio directo.");
                }
                $column = $assoc->joinColumnName;
                if (is_object($value)) {
                    $value = $this->extractAssociationValue($value, $assoc->referencedColumnName ?? 'id');
                }
            } else {
                throw new \InvalidArgumentException("Campo de criterio desconocido [{$field}] para {$meta->entityClass}.");
            }

            if ($value === null) {
                $parts[] = $this->connection->quoteIdentifier($column) . ' IS NULL';
                continue;
            }

            $parts[] = $this->connection->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        if ($meta->softDelete !== null) {
            $parts[] = $this->connection->quoteIdentifier($meta->softDelete->columnName) . ' IS NULL';
        }

        if ($meta->tenant !== null && $this->tenantId !== null) {
            $parts[] = $this->connection->quoteIdentifier($meta->tenant->columnName) . ' = ?';
            $params[] = $this->tenantId;
        }

        return [
            'where' => implode(' AND ', $parts),
            'params' => $params,
        ];
    }

    /**
     * @param array<string,mixed> $identifier
     * @return array<string,mixed>
     */
    private function normalizeIdentifierColumns(CompiledEntityMetadata $meta, mixed $identifier): array
    {
        if (!is_array($identifier)) {
            $idProperty = $meta->identifierPropertyNames[0] ?? null;
            if ($idProperty === null) {
                throw new \InvalidArgumentException("{$meta->entityClass} no define identificador.");
            }

            $propertyMeta = $meta->properties[$idProperty] ?? null;
            if ($propertyMeta === null) {
                throw new \InvalidArgumentException("Identifier property [{$idProperty}] no encontrada en {$meta->entityClass}.");
            }

            return [$propertyMeta->columnName => $identifier];
        }

        $normalized = [];
        foreach ($meta->identifierPropertyNames as $propertyName) {
            $propertyMeta = $meta->properties[$propertyName] ?? null;
            if ($propertyMeta === null) {
                continue;
            }

            if (array_key_exists($propertyName, $identifier)) {
                $normalized[$propertyMeta->columnName] = $identifier[$propertyName];
                continue;
            }

            if (array_key_exists($propertyMeta->columnName, $identifier)) {
                $normalized[$propertyMeta->columnName] = $identifier[$propertyMeta->columnName];
                continue;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException("Identificador inválido para {$meta->entityClass}.");
        }

        return $normalized;
    }

    private function takeManagedSnapshot(object $entity, CompiledEntityMetadata $meta): void
    {
        $identifier = new IdentifierExtractor($this->accessor);
        if (!$identifier->hasAllIds($entity, $meta)) {
            return;
        }

        $idHash = $identifier->hashId($entity, $meta, $this->tenantId);
        $this->changeTracker->refreshSnapshot($entity, $meta, $idHash);
    }

    private function resolveColumnName(CompiledEntityMetadata $meta, string $field): string
    {
        if (isset($meta->properties[$field])) {
            return $meta->properties[$field]->columnName;
        }

        if (isset($meta->columnToPropertyMap[$field])) {
            return $field;
        }

        throw new \InvalidArgumentException("Campo de orden desconocido [{$field}] para {$meta->entityClass}.");
    }

    private function normalizeOrderDirection(string $direction): string
    {
        return strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    }

    private function tableIdentifier(CompiledEntityMetadata $meta): string
    {
        if ($meta->schemaName !== null && $meta->schemaName !== '') {
            return $meta->schemaName . '.' . $meta->tableName;
        }

        return $meta->tableName;
    }

    /**
     * @param array<string,mixed> $identifierColumns
     */
    private function hashIdentifierValues(array $identifierColumns): string
    {
        if (count($identifierColumns) === 1) {
            $value = array_values($identifierColumns)[0];
            $base = $value === null ? '' : (is_scalar($value) ? (string) $value : $this->stableEncode($value));
        } else {
            ksort($identifierColumns);
            $base = $this->stableEncode($identifierColumns);
        }

        return $this->tenantId !== null ? $this->tenantId . '#' . $base : $base;
    }

    private function stableEncode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return substr(sha1(serialize($value)), 0, 40);
        }
    }

    private function extractAssociationValue(object $entity, string $columnOrProperty): mixed
    {
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            $name = $property->getName();
            foreach ($property->getAttributes() as $attribute) {
                $attrName = $attribute->getName();
                if (!str_ends_with($attrName, '\\Column') && $attrName !== 'Column') {
                    continue;
                }
                $args = $attribute->getArguments();
                $name = $args['name'] ?? $name;
                break;
            }

            if ($name !== $columnOrProperty && $property->getName() !== $columnOrProperty) {
                continue;
            }

            $property->setAccessible(true);
            return $property->isInitialized($entity) ? $property->getValue($entity) : null;
        }

        return null;
    }

    private function resolveDialect(ConnectionInterface $connection): DialectInterface
    {
        try {
            return DialectFactory::forDriver($connection->getDriverInfo()->driverName);
        } catch (\Throwable) {
            return new class implements DialectInterface {
                public function name(): string
                {
                    return 'generic';
                }

                public function quoteIdentifier(string $identifier): string
                {
                    $parts = explode('.', $identifier);
                    $parts = array_map(
                        static fn(string $part): string => '"' . str_replace('"', '""', $part) . '"',
                        $parts,
                    );

                    return implode('.', $parts);
                }

                public function parameterPlaceholder(int $index): string
                {
                    return '?';
                }

                public function quoteStyle(): string
                {
                    return 'double';
                }

                public function paramStyle(): string
                {
                    return 'positional_q';
                }

                public function compile(\Quantum\Database\Operation\DatabaseOperationInterface $op): \Quantum\Database\Dialect\Value\CompiledSql
                {
                    throw new \RuntimeException('Dialecto generic no soporta compile().');
                }

                public function normalizePlaceholders(string $sqlRaw): array
                {
                    return [
                        'sql' => $sqlRaw,
                        'count' => substr_count($sqlRaw, '?'),
                    ];
                }
            };
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function executeReadQuery(string $sql, array $params): \Quantum\Database\Dbal\Value\QueryResult
    {
        if ($this->operationRuntime === null) {
            $this->lastReadPlan = null;
            $this->lastReadDiagnostic = null;
            return $this->connection->executeQuery($sql, $params);
        }

        $context = $this->context->withConnection($this->connection);
        $plan = $this->operationRuntime->plan(
            operation: new RawOperation(
                kind: OperationKind::RawQuery,
                sql: $sql,
                params: $params,
                comment: $this->connection->getDriverInfo()->databaseName !== ''
                    ? $this->connection->getDriverInfo()->databaseName
                    : 'default',
            ),
            context: $context,
            policy: $this->runtimePolicyFromContext($context),
        );
        $result = $this->operationRuntime->execute($plan, $context);
        $this->lastReadPlan = $plan;
        $this->lastReadDiagnostic = $result->debug['diagnostic'] ?? null;

        if (!$result->queryResult instanceof \Quantum\Database\Dbal\Value\QueryResult) {
            throw new \RuntimeException('Runtime ORM read no devolvió QueryResult.');
        }

        return $result->queryResult;
    }

    private function runtimePolicyFromContext(DatabaseContext $context): DatabaseExecutionPolicy
    {
        $timeoutMs = $context->deadline?->remainingMs() ?? 30000;

        return new DatabaseExecutionPolicy(
            timeoutMs: max(1, $timeoutMs),
            maxRows: max(1, $context->maxRows),
            maxDepth: max(1, $context->maxDepth),
        );
    }
}
