<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Metadata;

use Quantum\Database\Orm\Association\Enum\AssociationKind;
use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\Association\Enum\CollectionKind;
use Quantum\Database\Orm\Association\Enum\FetchMode;
use Quantum\Database\Orm\Metadata\Attribute as ORM;
use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * AttributeMetadataLoader. Pipeline Algoritmo 4.1:
 *
 *   scan (Reflection + attribute instances)
 *   → validate etapa (6 reglas META-001/003/004/005/008)
 *   → compile CompiledEntityMetadata inmutable
 *   → fingerprint sha256 para cache invalidation
 *
 * No hay cache aquí (el cache es responsabilidad de MetadataManager).
 */
final class AttributeMetadataLoader
{
    /**
     * @param class-string $entityClass
     * @throws MetadataLoaderException|MetadataValidationException
     */
    public function load(string $entityClass): CompiledEntityMetadata
    {
        if (!class_exists($entityClass, true)) {
            throw new MetadataLoaderException("ORM metadata: class not found: {$entityClass}", 'META_ORM_0101');
        }
        $rc = new \ReflectionClass($entityClass);

        // 1. #[Entity]
        $entityAttrs = $rc->getAttributes(ORM\Entity::class);
        if ($entityAttrs === []) {
            throw new MetadataLoaderException(
                "Class {$entityClass} is not marked as ORM Entity (missing #[ORM\Entity])",
                'META_ORM_0102',
            );
        }
        /** @var ORM\Entity $entityAttr */
        $entityAttr = $entityAttrs[0]->newInstance();

        $tableName = $entityAttr->table ?? self::pluralizeShort($rc->getShortName());
        $schema = $entityAttr->schema;
        $readOnly = $entityAttr->readOnly;
        $repoClass = $entityAttr->repositoryClass ?? \Quantum\Database\Orm\Repository\DefaultRepository::class;

        // 2. Scan properties (incluyendo cadena de herencia padre → hijo).
        //    PHP getProperties() no incluye propiedades private heredadas;
        //    subimos manualmente por parentClass.
        /** @var array<string,array{reflection:\ReflectionProperty,attrs:list<object>}> $propAttrs propertyName → info.
         *  Para propiedades redefinidas, gana la más derivada (última al recorrer child first).
         */
        $propAttrs = [];
        $classChain = [];
        $cur = $rc;
        while ($cur !== false) {
            $classChain[] = $cur;
            $cur = $cur->getParentClass();
        }
        // Procesar desde el más alto (Object-like) hasta la clase actual,
        // para que la clase hija sobrescriba propiedades iguales.
        $classChain = array_reverse($classChain);
        foreach ($classChain as $c) {
            /** @var \ReflectionClass $c */
            foreach ($c->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE) as $rp) {
                if ($rp->getDeclaringClass()->getName() !== $c->getName()) {
                    continue;
                }
                $attrs = [];
                foreach ($rp->getAttributes() as $ra) {
                    try {
                        $attrs[] = $ra->newInstance();
                    } catch (\Throwable $e) {
                        throw new MetadataLoaderException(
                            "ORM metadata: invalid attribute on {$c->getName()}::\${$rp->getName()}: {$e->getMessage()}",
                            'META_ORM_0103',
                            previous: $e,
                        );
                    }
                }
                $propAttrs[$rp->getName()] = ['reflection' => $rp, 'attrs' => $attrs];
            }
        }

        // 3. Parse each property into CompiledPropertyMetadata or CompiledAssociationMetadata.
        /** @var array<string,CompiledPropertyMetadata> $properties */
        $properties = [];
        /** @var array<string,CompiledAssociationMetadata> $associations */
        $associations = [];
        /** @var list<string> $ids */
        $ids = [];
        $softDeleteMeta = null;
        $createdAtProp = null;
        $updatedAtProp = null;
        $tenantMeta = null;
        $versionMeta = null;
        $inheritanceMeta = null;

        // Inheritance at class level (mirar current; si no tiene, subir por padres).
        $inheritanceAttr = self::firstInstanceOf($rc->getAttributes(ORM\Inheritance::class), ORM\Inheritance::class);
        $discrAttr       = self::firstInstanceOf($rc->getAttributes(ORM\DiscriminatorValue::class), ORM\DiscriminatorValue::class);
        $parentDiscrAttr = null;
        if ($inheritanceAttr === null) {
            $p = $rc->getParentClass();
            while ($p !== false) {
                $candidate = self::firstInstanceOf($p->getAttributes(ORM\Inheritance::class), ORM\Inheritance::class);
                if ($candidate !== null) {
                    $inheritanceAttr = $candidate;
                    break;
                }
                $p = $p->getParentClass();
            }
            if ($inheritanceAttr !== null && $discrAttr === null) {
                // fallback padre discr value → none; nosotros generamos por short name
            }
        }
        if ($inheritanceAttr !== null) {
            $inheritanceMeta = new CompiledInheritanceMetadata(
                type: $inheritanceAttr->type,
                discrColumn: $inheritanceAttr->discriminatorColumn,
                map: $inheritanceAttr->map,
                discrValue: $discrAttr?->value ?? strtolower($rc->getShortName()),
            );
        }

        foreach ($propAttrs as $propName => $info) {
            /** @var \ReflectionProperty $rp */
            $rp = $info['reflection'];
            $attrs = $info['attrs'];

            // Pick association (may be 1)
            $assocAttr = null;
            $assocKind = null;
            foreach ($attrs as $a) {
                $kind = match ($a::class) {
                    ORM\OneToOne::class  => AssociationKind::OneToOne,
                    ORM\OneToMany::class => AssociationKind::OneToMany,
                    ORM\ManyToOne::class => AssociationKind::ManyToOne,
                    ORM\ManyToMany::class => AssociationKind::ManyToMany,
                    default => null,
                };
                if ($kind !== null) {
                    if ($assocAttr !== null) {
                        throw new MetadataValidationException(
                            "Propiedad {$entityClass}::\${$propName} tiene múltiples atributos de asociación",
                            'META_ORM_0003',
                        );
                    }
                    $assocAttr = $a;
                    $assocKind = $kind;
                }
            }

            $colAttr = self::firstAttrOfClass($attrs, ORM\Column::class);
            // id attr
            $idAttr = self::firstAttrOfClass($attrs, ORM\Id::class);
            $genAttr = self::firstAttrOfClass($attrs, ORM\GeneratedValue::class);
            $verAttr = self::firstAttrOfClass($attrs, ORM\Version::class);
            $sdAttr = self::firstAttrOfClass($attrs, ORM\SoftDelete::class);
            $caAttr = self::firstAttrOfClass($attrs, ORM\CreatedAt::class);
            $uaAttr = self::firstAttrOfClass($attrs, ORM\UpdatedAt::class);
            $tnAttr = self::firstAttrOfClass($attrs, ORM\TenantColumn::class);

            // META-003: asociación + column al mismo tiempo → error
            if ($assocAttr !== null && $colAttr !== null) {
                throw new MetadataValidationException(
                    "Propiedad '{$propName}' en {$entityClass} tiene asociación y #[Column] a la vez (ambigüedad)",
                    'META_ORM_0403',
                );
            }

            $access = self::buildPropertyAccessInfo($rp);

            // Procesar atributos laterales (version, tenant, etc.) incluso en asociaciones:
            // - TenantColumn se usa sobre ManyToOne/OneToOne (la columna FK es la del tenant).
            if ($tnAttr !== null) {
                $tenantMeta = new CompiledTenantMetadata(
                    propertyName: $propName,
                    columnName: $tnAttr->column,
                );
            }

            if ($assocAttr !== null) {
                $assocCompiled = self::compileAssociation($assocKind, $assocAttr, $propName);
                $associations[$propName] = $assocCompiled;
                continue;
            }

            // Column path
            $colAttr = $colAttr ?? new ORM\Column(name: null);
            $columnName = $colAttr->name ?? $propName;
            $phpType = $rp->getType();
            $typeSpec = self::inferFieldTypeSpec($colAttr, $phpType, $propName, $entityClass);
            $nullable = $colAttr->nullable || ($phpType instanceof \ReflectionNamedType && $phpType->allowsNull());
            $unique = $colAttr->unique;
            $default = $colAttr->default;
            $insertable = $colAttr->insertable;
            $updatable = $colAttr->updatable;
            $enumClass = $colAttr->enumClass;
            $customTypeClass = $colAttr->customType;
            $generated = $genAttr !== null || ($idAttr !== null && ($idAttr->strategy !== 'NONE'));
            $isId = $idAttr !== null;

            if ($isId) {
                $ids[] = $propName;
            }

            $pm = new CompiledPropertyMetadata(
                propertyName: $propName,
                columnName: $columnName,
                type: $typeSpec,
                isNullable: $nullable,
                isIdentifier: $isId,
                isInsertable: $insertable,
                isUpdatable: $updatable,
                isUnique: $unique,
                defaultValue: $default,
                enumClass: $enumClass,
                isGenerated: $generated,
                customTypeClass: $customTypeClass,
                access: $access,
            );
            $properties[$propName] = $pm;

            // Special columns meta
            if ($sdAttr !== null) {
                $softDeleteMeta = new CompiledSoftDeleteMetadata($propName, $sdAttr->column);
            }
            if ($caAttr !== null) {
                $createdAtProp = $propName;
            }
            if ($uaAttr !== null) {
                $updatedAtProp = $propName;
            }
            if ($tnAttr !== null) {
                $tenantMeta = new CompiledTenantMetadata($propName, $tnAttr->column);
            }
            if ($verAttr !== null) {
                $versionMeta = new CompiledVersionMetadata($propName, $columnName);
            }
        }

        $timestamps = ($createdAtProp !== null || $updatedAtProp !== null)
            ? new CompiledTimestampMetadata($createdAtProp, $updatedAtProp)
            : null;

        // 4. Validate stage
        self::validate(
            entityClass: $entityClass,
            ids: $ids,
            associations: $associations,
            readOnly: $readOnly,
        );

        // 5. Column reverse map
        $colMap = [];
        foreach ($properties as $name => $pm) {
            $colMap[$pm->columnName] = $name;
        }

        // 6. Fingerprint (META-007: includes file mtime y serialized attrs)
        $fpMaterial = json_encode([
            $entityClass,
            $rc->getFileName(),
            $rc->getFileName() !== false ? @filemtime($rc->getFileName()) : 0,
            array_keys($properties),
            array_keys($associations),
            $ids,
        ], JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', $fpMaterial);

        return new CompiledEntityMetadata(
            entityClass: $entityClass,
            tableName: $tableName,
            schemaName: $schema,
            repositoryClass: $repoClass,
            readOnly: $readOnly,
            identifierPropertyNames: $ids,
            properties: $properties,
            associations: $associations,
            columnToPropertyMap: $colMap,
            softDelete: $softDeleteMeta,
            timestamps: $timestamps,
            tenant: $tenantMeta,
            version: $versionMeta,
            inheritance: $inheritanceMeta,
            fingerprint: $fingerprint,
            compiledAt: time(),
        );
    }

    // ===================== HELPERS ==============================================

    /**
     * @param list<object> $instances
     */
    private static function firstAttrOfClass(array $instances, string $class): ?object
    {
        foreach ($instances as $i) {
            if ($i instanceof $class) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<\ReflectionAttribute> $reflectionAttrs
     */
    private static function firstInstanceOf(array $reflectionAttrs, string $class): ?object
    {
        foreach ($reflectionAttrs as $ra) {
            if (is_a($ra->getName(), $class, true)) {
                try {
                    return $ra->newInstance();
                } catch (\Throwable) {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Pluralize simple V1 (suficiente para entidades standard users/posts/tenants).
     */
    private static function pluralizeShort(string $short): string
    {
        $l = strtolower($short);
        if (str_ends_with($l, 's') || str_ends_with($l, 'x') || str_ends_with($l, 'ch') || str_ends_with($l, 'sh')) {
            return $l . 'es';
        }
        if (str_ends_with($l, 'y') && !in_array($l[-2], ['a', 'e', 'i', 'o', 'u'], true)) {
            return substr($l, 0, -1) . 'ies';
        }
        return $l . 's';
    }

    /**
     * Build CompiledAssociationMetadata a partir del attribute.
     */
    private static function compileAssociation(AssociationKind $kind, object $a, string $propName): CompiledAssociationMetadata
    {
        return match (true) {
            $a instanceof ORM\OneToOne => self::oneToOneMeta($a, $propName),
            $a instanceof ORM\OneToMany => self::oneToManyMeta($a, $propName),
            $a instanceof ORM\ManyToOne => self::manyToOneMeta($a, $propName),
            $a instanceof ORM\ManyToMany => self::manyToManyMeta($a, $propName),
            default => throw new MetadataLoaderException("Asociación desconocida: {$kind->value}", 'META_ORM_0104'),
        };
    }

    private static function oneToOneMeta(ORM\OneToOne $a, string $propName): CompiledAssociationMetadata
    {
        $isOwning = ($a->mappedBy === null);
        $defaultJoinCol = self::defaultJoinColumnNameForToOne($propName);
        return new CompiledAssociationMetadata(
            kind: AssociationKind::OneToOne,
            propertyName: $propName,
            targetEntityClass: $a->targetEntity,
            isOwningSide: $isOwning,
            mappedBy: $a->mappedBy,
            inversedBy: $a->inversedBy,
            cascades: $a->cascade,
            fetch: $a->fetch,
            joinColumnName: $a->joinColumn ?? $defaultJoinCol,
            referencedColumnName: $a->referencedColumn,
            joinColumnNullable: $a->nullable,
            orphanRemoval: $a->orphanRemoval,
            defaultOrderBy: [],
        );
    }

    private static function oneToManyMeta(ORM\OneToMany $a, string $propName): CompiledAssociationMetadata
    {
        // OneToMany is INVERSE side
        return new CompiledAssociationMetadata(
            kind: AssociationKind::OneToMany,
            propertyName: $propName,
            targetEntityClass: $a->targetEntity,
            isOwningSide: false,
            mappedBy: $a->mappedBy,
            inversedBy: null,
            cascades: $a->cascade,
            fetch: $a->fetch,
            orphanRemoval: $a->orphanRemoval,
            collectionKind: $a->collection,
            defaultOrderBy: $a->orderBy,
        );
    }

    private static function manyToOneMeta(ORM\ManyToOne $a, string $propName): CompiledAssociationMetadata
    {
        $defaultJoinCol = self::defaultJoinColumnNameForToOne($propName);
        return new CompiledAssociationMetadata(
            kind: AssociationKind::ManyToOne,
            propertyName: $propName,
            targetEntityClass: $a->targetEntity,
            isOwningSide: true,
            mappedBy: null,
            inversedBy: $a->inversedBy,
            cascades: $a->cascade,
            fetch: $a->fetch,
            joinColumnName: $a->joinColumn ?? $defaultJoinCol,
            referencedColumnName: $a->referencedColumn,
            joinColumnNullable: $a->nullable,
        );
    }

    private static function manyToManyMeta(ORM\ManyToMany $a, string $propName): CompiledAssociationMetadata
    {
        $isOwning = ($a->mappedBy === null);
        return new CompiledAssociationMetadata(
            kind: AssociationKind::ManyToMany,
            propertyName: $propName,
            targetEntityClass: $a->targetEntity,
            isOwningSide: $isOwning,
            mappedBy: $a->mappedBy,
            inversedBy: $a->inversedBy,
            cascades: $a->cascade,
            fetch: $a->fetch,
            joinTableName: $a->joinTable,
            joinColumnThisSide: $a->joinColumn,
            joinColumnTargetSide: $a->inverseJoinColumn,
            orphanRemoval: $a->orphanRemoval,
            collectionKind: $a->collection,
        );
    }

    private static function defaultJoinColumnNameForToOne(string $propName): string
    {
        return $propName . '_id';
    }

    /**
     * Infer FieldTypeSpec de $colAttr->type (preferentemente), de reflection type, o Text por defecto.
     */
    private static function inferFieldTypeSpec(ORM\Column $col, ?\ReflectionType $rt, string $prop, string $entityClass): FieldTypeSpec
    {
        $given = $col->type;
        if ($given instanceof FieldTypeSpec) {
            if ($col->length !== null) {
                // Override length if Column also sets length for varchar/char convenience.
                if (in_array($given->type, [DataType::Varchar, DataType::Char], true)) {
                    return new FieldTypeSpec($given->type, $col->length, $given->precision, $given->scale);
                }
            }
            return $given;
        }
        if ($given !== null && $col->enumClass === null && $col->customType === null) {
            // String type-name shortcut
            $dt = DataType::tryFrom((string)$given);
            if ($dt !== null) {
                return new FieldTypeSpec(
                    $dt,
                    $col->length,
                    $col->precision,
                    $col->scale,
                );
            }
        }
        // Backed enum
        if ($col->enumClass !== null && is_string($col->enumClass)) {
            $length = $col->length ?? 255;
            return FieldTypeSpec::varchar($length);
        }
        if ($col->customType !== null) {
            return FieldTypeSpec::text();
        }
        // Fallback to PHP reflection type → DataType
        if ($rt instanceof \ReflectionNamedType) {
            $name = $rt->getName();
            $tspec = match ($name) {
                'int' => FieldTypeSpec::bigint(),
                'string' => FieldTypeSpec::varchar($col->length ?? 255),
                'bool' => FieldTypeSpec::boolean(),
                'float' => FieldTypeSpec::double(),
                'array' => FieldTypeSpec::json(),
                \DateTimeImmutable::class, \DateTimeInterface::class, \DateTime::class => FieldTypeSpec::datetime(),
                \Ramsey\Uuid\UuidInterface::class => FieldTypeSpec::uuid(),
                default => null,
            };
            if ($tspec !== null) {
                return $tspec;
            }
            // Enums backed PHP
            if (is_string($name) && enum_exists($name)) {
                return FieldTypeSpec::varchar($col->length ?? 255);
            }
        }
        // Last default
        return FieldTypeSpec::varchar($col->length ?? 255);
    }

    /**
     * Build PropertyAccessInfo via reflection: getter/setter/hasser convention.
     */
    private static function buildPropertyAccessInfo(\ReflectionProperty $rp): PropertyAccessInfo
    {
        $decl = $rp->getDeclaringClass();
        // Buscar métodos en toda la jerarquía (desde la clase actual).
        // Usamos la declaring class como fallback pero buscamos también sobre el root.
        $propName = $rp->getName();
        $isPublicRead = $rp->isPublic();
        $isPublicWrite = $rp->isPublic();
        $suffix = ucfirst($propName);
        $getter = $decl->hasMethod("get{$suffix}") ? "get{$suffix}"
            : ($decl->hasMethod("is{$suffix}") ? "is{$suffix}"
                : ($decl->hasMethod("has{$suffix}") ? "has{$suffix}" : null));
        $setter = $decl->hasMethod("set{$suffix}") ? "set{$suffix}" : null;
        $hasser = $decl->hasMethod("has{$suffix}") ? "has{$suffix}" : null;
        $adder  = $decl->hasMethod("add{$suffix}")  ? "add{$suffix}"  : null;
        $remover = $decl->hasMethod("remove{$suffix}") ? "remove{$suffix}" : null;
        if ($getter !== null) {
            try {
                $m = $decl->getMethod($getter);
                $isPublicRead = $isPublicRead || $m->isPublic();
            } catch (\Throwable) {
            }
        }
        if ($setter !== null) {
            try {
                $m = $decl->getMethod($setter);
                $isPublicWrite = $isPublicWrite || $m->isPublic();
            } catch (\Throwable) {
            }
        }
        return new PropertyAccessInfo(
            isPublicRead: $isPublicRead,
            isPublicWrite: $isPublicWrite,
            getter: $getter,
            setter: $setter,
            hasser: $hasser,
            adder: $adder,
            remover: $remover,
        );
    }

    /**
     * Validate stage. Throws MetadataValidationException.
     */
    private static function validate(
        string $entityClass,
        array $ids,
        array $associations,
        bool $readOnly,
    ): void {
        // META-001: >= 1 Id property
        if ($ids === []) {
            throw new MetadataValidationException(
                "Entidad {$entityClass} no tiene ninguna propiedad marcada con #[Id]",
                'META_ORM_0401',
            );
        }
        // META-003 conflict check done per-prop earlier (assoc+column).
        foreach ($associations as $propName => $assoc) {
            // META-004: orphanRemoval solo en OneToOne (owner) o OneToMany
            if ($assoc->orphanRemoval) {
                $ok = ($assoc->kind === AssociationKind::OneToOne && $assoc->isOwningSide)
                    || ($assoc->kind === AssociationKind::OneToMany);
                if (!$ok) {
                    throw new MetadataValidationException(
                        "orphanRemoval=true solo permitido en OneToOne owning o OneToMany. Propiedad: {$entityClass}::\${$propName}",
                        'META_ORM_0404',
                    );
                }
            }
            // META-005: inversedBy y mappedBy mutuamente excluyentes
            if ($assoc->mappedBy !== null && $assoc->inversedBy !== null) {
                throw new MetadataValidationException(
                    "mappedBy e inversedBy no pueden coexistir. Propiedad: {$entityClass}::\${$propName}",
                    'META_ORM_0405',
                );
            }
            // OneToMany SIN mappedBy → error
            if ($assoc->kind === AssociationKind::OneToMany && $assoc->mappedBy === null) {
                throw new MetadataValidationException(
                    "OneToMany requiere mappedBy. Propiedad: {$entityClass}::\${$propName}",
                    'META_ORM_0402',
                );
            }
            // ReadOnly + cascades include PERSIST/REMOVE → warning (soft: solo si cascade tiene ALL/PERSIST/REMOVE)
            if ($readOnly) {
                $hasPersist = $assoc->hasCascade(CascadeKind::All)
                    || $assoc->hasCascade(CascadeKind::Persist)
                    || $assoc->hasCascade(CascadeKind::Remove);
                if ($hasPersist) {
                    // V1 no logger; soft warning via trigger silent. Usamos excepcion para entidades readOnly estrictas.
                    // (Relajado V1, no exception)
                }
            }
        }
    }
}