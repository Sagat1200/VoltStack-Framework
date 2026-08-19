<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

/**
 * Metadata compilada INMUTABLE de 1 entidad ORM. Cacheable.
 *
 * Regla META-002: final readonly (nadie puede mutar la instancia después de crearla).
 *
 * @template T of object
 */
final readonly class CompiledEntityMetadata
{
    /**
     * @param class-string<T> $entityClass
     * @param list<string> $identifierPropertyNames
     * @param array<string,CompiledPropertyMetadata> $properties propertyName → metadata
     * @param array<string,CompiledAssociationMetadata> $associations propertyName → association metadata
     * @param array<string,string> $columnToPropertyMap reverse lookup hydration rápida
     */
    public function __construct(
        public string $entityClass,
        public string $tableName,
        public ?string $schemaName,
        public string $repositoryClass,
        public bool $readOnly,
        public array $identifierPropertyNames,
        public array $properties,
        public array $associations,
        public array $columnToPropertyMap,
        public ?CompiledSoftDeleteMetadata $softDelete,
        public ?CompiledTimestampMetadata $timestamps,
        public ?CompiledTenantMetadata $tenant,
        public ?CompiledVersionMetadata $version,
        public ?CompiledInheritanceMetadata $inheritance,
        public string $fingerprint,
        public int $compiledAt,
    ) {}

    /**
     * @return list<CompiledPropertyMetadata>
     */
    public function getIdentifierProperties(): array
    {
        return array_values(array_filter(
            $this->properties,
            static fn($p): bool => in_array($p->propertyName, $this->identifierPropertyNames, true),
        ));
    }

    public function hasAssociation(string $propertyName): bool
    {
        return isset($this->associations[$propertyName]);
    }

    public function getAssociation(string $propertyName): CompiledAssociationMetadata
    {
        return $this->associations[$propertyName];
    }

    public function getPropertyForColumn(string $column): ?CompiledPropertyMetadata
    {
        $prop = $this->columnToPropertyMap[$column] ?? null;
        if ($prop === null) {
            return null;
        }
        return $this->properties[$prop] ?? null;
    }
}
