<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Schema\MysqlSchemaIntrospector;
use Quantum\Database\Schema\PgsqlSchemaIntrospector;

final class PortableSchemaIntrospectorTest extends TestCase
{
    public function test_mysql_introspector_extracts_columns_indexes_and_foreign_keys(): void
    {
        $connection = new IntrospectionConnection('mysql', static function (string $sql, array $params): array {
            return match (true) {
                str_contains($sql, 'information_schema.columns') => [
                    ['table_schema' => 'volt', 'column_name' => 'id', 'column_type' => 'bigint', 'data_type' => 'bigint', 'is_nullable' => 'NO', 'column_default' => null, 'column_key' => 'PRI', 'extra' => 'auto_increment', 'ordinal_position' => 1, 'character_maximum_length' => null, 'numeric_precision' => 20, 'numeric_scale' => 0],
                    ['table_schema' => 'volt', 'column_name' => 'email', 'column_type' => 'varchar(255)', 'data_type' => 'varchar', 'is_nullable' => 'NO', 'column_default' => null, 'column_key' => '', 'extra' => '', 'ordinal_position' => 2, 'character_maximum_length' => 255, 'numeric_precision' => null, 'numeric_scale' => null],
                    ['table_schema' => 'volt', 'column_name' => 'role_id', 'column_type' => 'int', 'data_type' => 'int', 'is_nullable' => 'NO', 'column_default' => null, 'column_key' => 'MUL', 'extra' => '', 'ordinal_position' => 3, 'character_maximum_length' => null, 'numeric_precision' => 10, 'numeric_scale' => 0],
                ],
                str_contains($sql, "constraint_name = 'PRIMARY'") => [
                    ['column_name' => 'id'],
                ],
                str_contains($sql, 'information_schema.statistics') => [
                    ['index_name' => 'PRIMARY', 'non_unique' => 0, 'seq_in_index' => 1, 'column_name' => 'id'],
                    ['index_name' => 'uq_users_email', 'non_unique' => 0, 'seq_in_index' => 1, 'column_name' => 'email'],
                    ['index_name' => 'idx_users_role_id', 'non_unique' => 1, 'seq_in_index' => 1, 'column_name' => 'role_id'],
                ],
                str_contains($sql, 'information_schema.referential_constraints') => [
                    ['constraint_name' => 'fk_users_role_id', 'column_name' => 'role_id', 'referenced_table_schema' => 'volt', 'referenced_table_name' => 'roles', 'referenced_column_name' => 'id', 'update_rule' => 'CASCADE', 'delete_rule' => 'RESTRICT', 'ordinal_position' => 1],
                ],
                default => [],
            };
        });

        $table = (new MysqlSchemaIntrospector($connection))->describeTable('users');

        self::assertSame('volt', $table->schemaName);
        self::assertSame(['id'], $table->primaryKey);
        self::assertSame('bigint', $table->column('id')?->portableType);
        self::assertTrue($table->column('id')?->autoIncrement ?? false);
        self::assertSame('varchar', $table->column('email')?->portableType);
        self::assertCount(3, $table->indexes);
        self::assertSame('fk_users_role_id', $table->foreignKeys[0]->name);
        self::assertSame('roles', $table->foreignKeys[0]->referencedTable);
        self::assertSame('CASCADE', $table->foreignKeys[0]->onUpdate);
    }

    public function test_pgsql_introspector_qualifies_non_public_tables_and_reads_metadata(): void
    {
        $connection = new IntrospectionConnection('pgsql', static function (string $sql, array $params): array {
            return match (true) {
                str_contains($sql, 'information_schema.tables') => [
                    ['table_schema' => 'public', 'table_name' => 'users'],
                    ['table_schema' => 'audit', 'table_name' => 'logs'],
                ],
                str_contains($sql, 'information_schema.columns') => [
                    ['table_schema' => 'public', 'column_name' => 'id', 'data_type' => 'integer', 'udt_name' => 'int4', 'is_nullable' => 'NO', 'column_default' => "nextval('users_id_seq'::regclass)", 'ordinal_position' => 1, 'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'is_identity' => 'NO'],
                    ['table_schema' => 'public', 'column_name' => 'email', 'data_type' => 'character varying', 'udt_name' => 'varchar', 'is_nullable' => 'NO', 'column_default' => null, 'ordinal_position' => 2, 'character_maximum_length' => 255, 'numeric_precision' => null, 'numeric_scale' => null, 'is_identity' => 'NO'],
                    ['table_schema' => 'public', 'column_name' => 'account_id', 'data_type' => 'integer', 'udt_name' => 'int4', 'is_nullable' => 'NO', 'column_default' => null, 'ordinal_position' => 3, 'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'is_identity' => 'NO'],
                ],
                str_contains($sql, "constraint_type = 'PRIMARY KEY'") => [
                    ['column_name' => 'id'],
                ],
                str_contains($sql, 'string_agg(a.attname') => [
                    ['index_name' => 'users_pkey', 'is_unique' => 't', 'is_primary' => 't', 'columns' => 'id'],
                    ['index_name' => 'users_email_key', 'is_unique' => 't', 'is_primary' => 'f', 'columns' => 'email'],
                ],
                str_contains($sql, "constraint_type = 'FOREIGN KEY'") => [
                    ['constraint_name' => 'fk_users_account_id', 'column_name' => 'account_id', 'referenced_schema' => 'public', 'referenced_table' => 'accounts', 'referenced_column' => 'id', 'update_rule' => 'CASCADE', 'delete_rule' => 'CASCADE', 'ordinal_position' => 1],
                ],
                default => [],
            };
        });

        $introspector = new PgsqlSchemaIntrospector($connection);

        self::assertSame(['users', 'audit.logs'], $introspector->listTables());

        $table = $introspector->describeTable('users');

        self::assertSame('public', $table->schemaName);
        self::assertSame(['id'], $table->primaryKey);
        self::assertSame('varchar', $table->column('email')?->portableType);
        self::assertCount(2, $table->indexes);
        self::assertTrue($table->indexes[0]->primary);
        self::assertSame('fk_users_account_id', $table->foreignKeys[0]->name);
        self::assertSame('public', $table->foreignKeys[0]->referencedSchema);
        self::assertSame('CASCADE', $table->foreignKeys[0]->onDelete);
    }
}

final class IntrospectionConnection implements ConnectionInterface
{
    /**
     * @param \Closure(string,array):list<array<string,mixed>> $resolver
     */
    public function __construct(
        private readonly string $driver,
        private readonly \Closure $resolver,
    ) {
    }

    public function connect(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function close(): void
    {
    }

    public function ping(): bool
    {
        return true;
    }

    public function prepare(string $sql): StatementInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeStatement(string $sql, array $params = []): QueryResult
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        $rows = ($this->resolver)($sql, $params);

        return new QueryResult(
            isSelect: true,
            affectedRows: count($rows),
            columnMeta: [],
            rowGenerator: static function () use ($rows): \Generator {
                foreach ($rows as $row) {
                    yield $row;
                }
            },
            cleanup: static function (): void {
            },
        );
    }

    public function lastInsertId(?string $sequenceName = null): string|int|null
    {
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        $quote = in_array($this->driver, ['mysql', 'mariadb'], true) ? '`' : '"';
        $parts = explode('.', $identifier);

        return implode('.', array_map(
            static fn(string $part): string => $quote . $part . $quote,
            $parts,
        ));
    }

    public function quoteString(string $value): string
    {
        return "'" . $value . "'";
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }

    public function createSavepoint(string $identifier): void
    {
    }

    public function releaseSavepoint(string $identifier): void
    {
    }

    public function rollbackToSavepoint(string $identifier): void
    {
    }

    public function setTransactionIsolation(TransactionIsolation $level): void
    {
    }

    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            driverName: $this->driver,
            serverVersion: 'test',
            databaseName: 'test',
        );
    }

    public function getCapabilities(): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::minimalSet($this->driver);
    }

    public function lastUsedAtSeconds(): float
    {
        return 0.0;
    }

    public function getNativeHandle(): mixed
    {
        return null;
    }
}
