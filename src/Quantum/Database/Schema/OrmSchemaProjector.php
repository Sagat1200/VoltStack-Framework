<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Orm\Association\Enum\AssociationKind;
use Quantum\Database\Orm\Metadata\CompiledAssociationMetadata;
use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;
use Quantum\Database\Orm\Metadata\FieldTypeSpec;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;

final class OrmSchemaProjector
{
    public function __construct(
        private readonly MetadataManagerInterface $metadata,
        private readonly EntityDiscovery $discovery,
        private readonly string $driverName,
    ) {}

    public function project(): SchemaSnapshot
    {
        $entities = $this->discovery->discover();
        $this->metadata->warmup($entities);

        $tables = [];

        foreach ($entities as $entityClass) {
            $meta = $this->metadata->getMetadataFor($entityClass);
            $tables[] = $this->projectEntity($meta);
        }

        usort($tables, static fn(SchemaTable $a, SchemaTable $b): int => strcmp($a->name, $b->name));

        return new SchemaSnapshot(
            driver: $this->driverName,
            tables: $tables,
        );
    }

    private function projectEntity(CompiledEntityMetadata $meta): SchemaTable
    {
        $columns = [];
        $primary = [];
        $indexes = [];
        $foreignKeys = [];
        $knownColumns = [];

        foreach ($meta->properties as $property) {
            $type = $this->typeDefinition($property->type);
            $column = new SchemaColumn(
                name: $property->columnName,
                nativeType: $type['native_type'],
                nullable: $property->isNullable,
                defaultValue: $property->defaultValue,
                primaryKey: $property->isIdentifier,
                autoIncrement: $property->isIdentifier && $property->isGenerated,
                ordinal: count($columns),
                portableType: $type['portable_type'],
                length: $type['length'],
                precision: $type['precision'],
                scale: $type['scale'],
            );

            $columns[] = $column;
            $knownColumns[$column->name] = true;

            if ($column->primaryKey) {
                $primary[] = $column->name;
            }

            if ($property->isUnique) {
                $indexes[] = new SchemaIndex(
                    name: sprintf('uq_%s_%s', $meta->tableName, $column->name),
                    columns: [$column->name],
                    unique: true,
                );
            }
        }

        foreach ($meta->associations as $association) {
            if (!$this->supportsJoinColumnProjection($association)) {
                continue;
            }

            $targetMeta = $this->metadata->getMetadataFor($association->targetEntityClass);
            $targetIds = $targetMeta->getIdentifierProperties();
            if (count($targetIds) !== 1) {
                continue;
            }

            $targetId = $targetIds[0];
            $joinColumnName = $association->joinColumnName ?? ($association->propertyName . '_id');
            if (!isset($knownColumns[$joinColumnName])) {
                $type = $this->typeDefinition($targetId->type);
                $columns[] = new SchemaColumn(
                    name: $joinColumnName,
                    nativeType: $type['native_type'],
                    nullable: $association->joinColumnNullable,
                    defaultValue: null,
                    primaryKey: false,
                    autoIncrement: false,
                    ordinal: count($columns),
                    portableType: $type['portable_type'],
                    length: $type['length'],
                    precision: $type['precision'],
                    scale: $type['scale'],
                );
                $knownColumns[$joinColumnName] = true;
            }

            if ($association->kind === AssociationKind::OneToOne) {
                $indexes[] = new SchemaIndex(
                    name: sprintf('uq_%s_%s', $meta->tableName, $joinColumnName),
                    columns: [$joinColumnName],
                    unique: true,
                );
            }

            $foreignKeys[] = new SchemaForeignKey(
                name: sprintf('fk_%s_%s', $meta->tableName, $joinColumnName),
                columns: [$joinColumnName],
                referencedTable: $targetMeta->tableName,
                referencedColumns: [$association->referencedColumnName ?? $targetId->columnName],
                referencedSchema: $targetMeta->schemaName,
            );
        }

        usort($columns, static fn(SchemaColumn $a, SchemaColumn $b): int => $a->ordinal <=> $b->ordinal);

        $createParts = [];
        $singlePrimaryKey = count($primary) === 1;

        foreach ($columns as $column) {
            $createParts[] = $this->columnSql(
                column: $column,
                inlinePrimaryKey: $singlePrimaryKey && $column->primaryKey,
            );
        }

        if (count($primary) > 1) {
            $quoted = array_map($this->quoteIdentifier(...), $primary);
            $createParts[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
        }

        return new SchemaTable(
            name: $meta->tableName,
            columns: $columns,
            primaryKey: $primary,
            createSql: 'CREATE TABLE ' . $this->quoteQualifiedIdentifier($meta->schemaName, $meta->tableName) . ' (' . implode(', ', $createParts) . ')',
            schemaName: $meta->schemaName,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    /**
     * @return array{native_type:string,portable_type:?string,length:?int,precision:?int,scale:?int}
     */
    private function typeDefinition(FieldTypeSpec $type): array
    {
        return match ($this->driverName) {
            'sqlite' => $this->sqliteTypeDefinition($type),
            'pgsql' => $this->pgsqlTypeDefinition($type),
            'mysql', 'mariadb' => $this->mysqlTypeDefinition($type),
            default => [
                'native_type' => strtoupper((string) $type),
                'portable_type' => $this->portableTypeForDriver($type),
                'length' => $type->length,
                'precision' => $type->precision,
                'scale' => $type->scale,
            ],
        };
    }

    /**
     * @return array{native_type:string,portable_type:?string,length:?int,precision:?int,scale:?int}
     */
    private function sqliteTypeDefinition(FieldTypeSpec $type): array
    {
        $native = match ($type->type) {
            DataType::Int2, DataType::Int4, DataType::Int8, DataType::Bool => 'INTEGER',
            DataType::Float4, DataType::Float8, DataType::Numeric => 'REAL',
            DataType::ByteA, DataType::Blob => 'BLOB',
            default => 'TEXT',
        };

        return [
            'native_type' => $native,
            'portable_type' => match ($native) {
                'INTEGER' => 'integer',
                'REAL' => 'float',
                'BLOB' => 'blob',
                default => 'text',
            },
            'length' => null,
            'precision' => null,
            'scale' => null,
        ];
    }

    /**
     * @return array{native_type:string,portable_type:?string,length:?int,precision:?int,scale:?int}
     */
    private function pgsqlTypeDefinition(FieldTypeSpec $type): array
    {
        return match ($type->type) {
            DataType::Int2 => ['native_type' => 'SMALLINT', 'portable_type' => 'smallint', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Int4 => ['native_type' => 'INTEGER', 'portable_type' => 'integer', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Int8 => ['native_type' => 'BIGINT', 'portable_type' => 'bigint', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Bool => ['native_type' => 'BOOLEAN', 'portable_type' => 'boolean', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Float4 => ['native_type' => 'REAL', 'portable_type' => 'float', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Float8 => ['native_type' => 'DOUBLE PRECISION', 'portable_type' => 'double', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Numeric => ['native_type' => sprintf('NUMERIC(%d,%d)', $type->precision ?? 18, $type->scale ?? 2), 'portable_type' => 'decimal', 'length' => null, 'precision' => $type->precision ?? 18, 'scale' => $type->scale ?? 2],
            DataType::Varchar => ['native_type' => sprintf('VARCHAR(%d)', $type->length ?? 255), 'portable_type' => 'varchar', 'length' => $type->length ?? 255, 'precision' => null, 'scale' => null],
            DataType::Char => ['native_type' => sprintf('CHAR(%d)', $type->length ?? 1), 'portable_type' => 'char', 'length' => $type->length ?? 1, 'precision' => null, 'scale' => null],
            DataType::ByteA, DataType::Blob => ['native_type' => 'BYTEA', 'portable_type' => 'blob', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Date => ['native_type' => 'DATE', 'portable_type' => 'date', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Time => ['native_type' => 'TIME', 'portable_type' => 'time', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Timestamp => ['native_type' => 'TIMESTAMP', 'portable_type' => 'timestamp', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::TimestampTz => ['native_type' => 'TIMESTAMPTZ', 'portable_type' => 'timestamp', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Json, DataType::JsonB => ['native_type' => $type->type === DataType::Json ? 'JSON' : 'JSONB', 'portable_type' => 'json', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Uuid => ['native_type' => 'UUID', 'portable_type' => 'uuid', 'length' => null, 'precision' => null, 'scale' => null],
            default => ['native_type' => 'TEXT', 'portable_type' => 'text', 'length' => null, 'precision' => null, 'scale' => null],
        };
    }

    /**
     * @return array{native_type:string,portable_type:?string,length:?int,precision:?int,scale:?int}
     */
    private function mysqlTypeDefinition(FieldTypeSpec $type): array
    {
        return match ($type->type) {
            DataType::Int2 => ['native_type' => 'SMALLINT', 'portable_type' => 'smallint', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Int4 => ['native_type' => 'INT', 'portable_type' => 'integer', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Int8 => ['native_type' => 'BIGINT', 'portable_type' => 'bigint', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Bool => ['native_type' => 'TINYINT(1)', 'portable_type' => 'boolean', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Float4 => ['native_type' => 'FLOAT', 'portable_type' => 'float', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Float8 => ['native_type' => 'DOUBLE', 'portable_type' => 'double', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Numeric => ['native_type' => sprintf('DECIMAL(%d,%d)', $type->precision ?? 18, $type->scale ?? 2), 'portable_type' => 'decimal', 'length' => null, 'precision' => $type->precision ?? 18, 'scale' => $type->scale ?? 2],
            DataType::Varchar => ['native_type' => sprintf('VARCHAR(%d)', $type->length ?? 255), 'portable_type' => 'varchar', 'length' => $type->length ?? 255, 'precision' => null, 'scale' => null],
            DataType::Char => ['native_type' => sprintf('CHAR(%d)', $type->length ?? 1), 'portable_type' => 'char', 'length' => $type->length ?? 1, 'precision' => null, 'scale' => null],
            DataType::ByteA, DataType::Blob => ['native_type' => 'BLOB', 'portable_type' => 'blob', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Date => ['native_type' => 'DATE', 'portable_type' => 'date', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Time => ['native_type' => 'TIME', 'portable_type' => 'time', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Timestamp, DataType::TimestampTz => ['native_type' => 'DATETIME', 'portable_type' => 'timestamp', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Json, DataType::JsonB => ['native_type' => 'JSON', 'portable_type' => 'json', 'length' => null, 'precision' => null, 'scale' => null],
            DataType::Uuid => ['native_type' => 'CHAR(36)', 'portable_type' => 'uuid', 'length' => 36, 'precision' => null, 'scale' => null],
            default => ['native_type' => 'TEXT', 'portable_type' => 'text', 'length' => null, 'precision' => null, 'scale' => null],
        };
    }

    private function portableTypeForDriver(FieldTypeSpec $type): ?string
    {
        return match ($type->type) {
            DataType::Int2 => 'smallint',
            DataType::Int4 => 'integer',
            DataType::Int8 => 'bigint',
            DataType::Bool => 'boolean',
            DataType::Float4 => 'float',
            DataType::Float8 => 'double',
            DataType::Numeric => 'decimal',
            DataType::Varchar => 'varchar',
            DataType::Char => 'char',
            DataType::ByteA, DataType::Blob => 'blob',
            DataType::Date => 'date',
            DataType::Time => 'time',
            DataType::Timestamp, DataType::TimestampTz => 'timestamp',
            DataType::Json, DataType::JsonB => 'json',
            DataType::Uuid => 'uuid',
            default => 'text',
        };
    }

    private function supportsJoinColumnProjection(CompiledAssociationMetadata $association): bool
    {
        return $association->isOwningSide
            && in_array($association->kind, [AssociationKind::ManyToOne, AssociationKind::OneToOne], true);
    }

    private function columnSql(SchemaColumn $column, bool $inlinePrimaryKey = false): string
    {
        $parts = [
            $this->quoteIdentifier($column->name),
            $column->nativeType,
        ];

        if ($inlinePrimaryKey && $column->autoIncrement) {
            $parts[] = match ($this->driverName) {
                'pgsql' => 'GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                'mysql', 'mariadb' => 'AUTO_INCREMENT PRIMARY KEY',
                default => 'PRIMARY KEY AUTOINCREMENT',
            };
            return implode(' ', $parts);
        }

        if ($inlinePrimaryKey) {
            $parts[] = 'PRIMARY KEY';
        }

        if (!$column->nullable) {
            $parts[] = 'NOT NULL';
        }

        if ($column->defaultValue !== null) {
            $parts[] = 'DEFAULT ' . $this->normalizeDefault($column->defaultValue);
        }

        return implode(' ', $parts);
    }

    private function normalizeDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        $string = (string) $value;
        if (
            str_starts_with($string, "'")
            || str_starts_with($string, '"')
            || preg_match('/^\w+\(.*\)$/', $string) === 1
        ) {
            return $string;
        }

        return "'" . str_replace("'", "''", $string) . "'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        $quote = in_array($this->driverName, ['mysql', 'mariadb'], true) ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function quoteQualifiedIdentifier(?string $schema, string $name): string
    {
        if ($schema === null || $schema === '') {
            return $this->quoteIdentifier($name);
        }

        return $this->quoteIdentifier($schema) . '.' . $this->quoteIdentifier($name);
    }
}
