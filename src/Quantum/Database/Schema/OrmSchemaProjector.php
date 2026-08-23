<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

use Quantum\Database\Operation\Sqg\Enum\DataType;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;

final class OrmSchemaProjector
{
    public function __construct(
        private readonly MetadataManagerInterface $metadata,
        private readonly EntityDiscovery $discovery,
        private readonly string $driverName,
    ) {
    }

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
        $createParts = [];

        foreach ($meta->properties as $property) {
            $column = new SchemaColumn(
                name: $property->columnName,
                nativeType: $this->mapNativeType($property),
                nullable: $property->isNullable,
                defaultValue: $property->defaultValue,
                primaryKey: $property->isIdentifier,
                autoIncrement: $property->isIdentifier && $property->isGenerated,
                ordinal: count($columns),
            );

            $columns[] = $column;
            $createParts[] = $this->columnSql($column);

            if ($column->primaryKey) {
                $primary[] = $column->name;
            }
        }

        if (count($primary) > 1) {
            $quoted = array_map($this->quoteIdentifier(...), $primary);
            $createParts[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
        }

        return new SchemaTable(
            name: $meta->tableName,
            columns: $columns,
            primaryKey: $primary,
            createSql: 'CREATE TABLE ' . $this->quoteIdentifier($meta->tableName) . ' (' . implode(', ', $createParts) . ')',
        );
    }

    private function mapNativeType(CompiledPropertyMetadata $property): string
    {
        return match ($this->driverName) {
            'sqlite' => $this->mapSqliteType($property),
            default => strtoupper((string) $property->type),
        };
    }

    private function mapSqliteType(CompiledPropertyMetadata $property): string
    {
        return match ($property->type->type) {
            DataType::Int2, DataType::Int4, DataType::Int8, DataType::Bool => 'INTEGER',
            DataType::Float4, DataType::Float8, DataType::Numeric => 'REAL',
            DataType::ByteA, DataType::Blob => 'BLOB',
            default => 'TEXT',
        };
    }

    private function columnSql(SchemaColumn $column): string
    {
        $parts = [
            $this->quoteIdentifier($column->name),
            $column->nativeType,
        ];

        if ($column->primaryKey && $column->autoIncrement) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
            return implode(' ', $parts);
        }

        if ($column->primaryKey) {
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
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
