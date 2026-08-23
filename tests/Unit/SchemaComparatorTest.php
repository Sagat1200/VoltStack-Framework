<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Schema\SchemaColumn;
use Quantum\Database\Schema\SchemaComparator;
use Quantum\Database\Schema\SchemaForeignKey;
use Quantum\Database\Schema\SchemaIndex;
use Quantum\Database\Schema\SchemaSnapshot;
use Quantum\Database\Schema\SchemaTable;

final class SchemaComparatorTest extends TestCase
{
    public function test_it_treats_portable_equivalents_as_equal_even_when_native_names_differ(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'CHARACTER VARYING(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('status', 'CHARACTER VARYING(255)', false, "'draft'::character varying", false, false, 2, 'varchar', 255),
                    new SchemaColumn('account_id', 'INTEGER', false, null, false, false, 3, 'integer'),
                ],
                primaryKey: ['id'],
                indexes: [
                    new SchemaIndex('users_email_unique', ['email'], unique: true),
                ],
                foreignKeys: [
                    new SchemaForeignKey('users_account_id_fk', ['account_id'], 'accounts', ['id'], 'public', onDelete: 'CASCADE'),
                ],
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INT', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('status', 'VARCHAR(255)', false, 'draft', false, false, 2, 'varchar', 255),
                    new SchemaColumn('account_id', 'INT', false, null, false, false, 3, 'integer'),
                ],
                primaryKey: ['id'],
                indexes: [
                    new SchemaIndex('uq_users_email', ['email'], unique: true),
                ],
                foreignKeys: [
                    new SchemaForeignKey('fk_users_account_id', ['account_id'], 'accounts', ['id'], 'public', onDelete: 'CASCADE'),
                ],
            ),
        ]);

        $report = (new SchemaComparator())->compare($actual, $desired);

        self::assertTrue($report->isEmpty());
    }

    public function test_it_reports_missing_indexes_and_foreign_keys_with_driver_specific_sql(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('account_id', 'INTEGER', false, null, false, false, 2, 'integer'),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('account_id', 'INTEGER', false, null, false, false, 2, 'integer'),
                ],
                primaryKey: ['id'],
                indexes: [
                    new SchemaIndex('uq_users_email', ['email'], unique: true),
                ],
                foreignKeys: [
                    new SchemaForeignKey('fk_users_account_id', ['account_id'], 'accounts', ['id'], 'public', onDelete: 'CASCADE'),
                ],
            ),
        ]);

        $report = (new SchemaComparator())->compare($actual, $desired);
        $actions = $report->actions;

        self::assertCount(2, $actions);
        self::assertSame('create_index', $actions[0]->kind);
        self::assertSame('CREATE UNIQUE INDEX "uq_users_email" ON "public"."users" ("email")', $actions[0]->sql);
        self::assertSame('add_foreign_key', $actions[1]->kind);
        self::assertSame('ALTER TABLE "public"."users" ADD CONSTRAINT "fk_users_account_id" FOREIGN KEY ("account_id") REFERENCES "public"."accounts" ("id") ON DELETE CASCADE', $actions[1]->sql);
    }

    public function test_it_generates_pgsql_modify_column_sql_with_rollback(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(120)', true, null, false, false, 1, 'varchar', 120),
                    new SchemaColumn('status', 'VARCHAR(20)', false, "'draft'::character varying", false, false, 2, 'varchar', 20),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('status', 'VARCHAR(32)', false, 'active', false, false, 2, 'varchar', 32),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(2, $actions);
        self::assertSame('modify_column', $actions[0]->kind);
        self::assertSame(
            'ALTER TABLE "public"."users" ALTER COLUMN "email" TYPE VARCHAR(255), ALTER COLUMN "email" SET NOT NULL',
            $actions[0]->sql,
        );
        self::assertSame(
            'ALTER TABLE "public"."users" ALTER COLUMN "email" TYPE VARCHAR(120), ALTER COLUMN "email" DROP NOT NULL',
            $actions[0]->rollbackSql,
        );
        self::assertSame('modify_column', $actions[1]->kind);
        self::assertSame(
            'ALTER TABLE "public"."users" ALTER COLUMN "status" TYPE VARCHAR(32), ALTER COLUMN "status" SET DEFAULT \'active\'',
            $actions[1]->sql,
        );
        self::assertSame(
            'ALTER TABLE "public"."users" ALTER COLUMN "status" TYPE VARCHAR(20), ALTER COLUMN "status" SET DEFAULT \'draft\'::character varying',
            $actions[1]->rollbackSql,
        );
    }

    public function test_it_generates_mysql_modify_column_sql_with_rollback(): void
    {
        $actual = new SchemaSnapshot('mysql', [
            new SchemaTable(
                name: 'users',
                columns: [
                    new SchemaColumn('id', 'INT', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(120)', true, null, false, false, 1, 'varchar', 120),
                    new SchemaColumn('status', 'VARCHAR(20)', false, 'draft', false, false, 2, 'varchar', 20),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $desired = new SchemaSnapshot('mysql', [
            new SchemaTable(
                name: 'users',
                columns: [
                    new SchemaColumn('id', 'INT', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('status', 'VARCHAR(32)', false, 'active', false, false, 2, 'varchar', 32),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(2, $actions);
        self::assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(255) NOT NULL',
            $actions[0]->sql,
        );
        self::assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(120) NULL',
            $actions[0]->rollbackSql,
        );
        self::assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT \'active\'',
            $actions[1]->sql,
        );
        self::assertSame(
            'ALTER TABLE `users` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT \'draft\'',
            $actions[1]->rollbackSql,
        );
    }

    public function test_it_generates_pgsql_modify_primary_key_sql_with_rollback(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INTEGER', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INTEGER', false, null, false, false, 1, 'integer'),
                ],
                primaryKey: ['user_id'],
                primaryKeyName: 'memberships_pk_custom',
                schemaName: 'public',
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INTEGER', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INTEGER', false, null, true, false, 1, 'integer'),
                ],
                primaryKey: ['user_id', 'tenant_id'],
                primaryKeyName: 'memberships_pkey',
                schemaName: 'public',
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(1, $actions);
        self::assertSame('modify_primary_key', $actions[0]->kind);
        self::assertSame(
            'ALTER TABLE "public"."memberships" DROP CONSTRAINT "memberships_pk_custom", ADD CONSTRAINT "memberships_pkey" PRIMARY KEY ("user_id", "tenant_id")',
            $actions[0]->sql,
        );
        self::assertSame(
            'ALTER TABLE "public"."memberships" DROP CONSTRAINT "memberships_pkey", ADD CONSTRAINT "memberships_pk_custom" PRIMARY KEY ("user_id")',
            $actions[0]->rollbackSql,
        );
    }

    public function test_it_generates_mysql_modify_primary_key_sql_with_rollback(): void
    {
        $actual = new SchemaSnapshot('mysql', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INT', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INT', false, null, false, false, 1, 'integer'),
                ],
                primaryKey: ['user_id'],
                primaryKeyName: 'PRIMARY',
            ),
        ]);

        $desired = new SchemaSnapshot('mysql', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INT', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INT', false, null, true, false, 1, 'integer'),
                ],
                primaryKey: ['user_id', 'tenant_id'],
                primaryKeyName: 'PRIMARY',
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(1, $actions);
        self::assertSame('modify_primary_key', $actions[0]->kind);
        self::assertSame(
            'ALTER TABLE `memberships` DROP PRIMARY KEY, ADD PRIMARY KEY (`user_id`, `tenant_id`)',
            $actions[0]->sql,
        );
        self::assertSame(
            'ALTER TABLE `memberships` DROP PRIMARY KEY, ADD PRIMARY KEY (`user_id`)',
            $actions[0]->rollbackSql,
        );
    }

    public function test_it_generates_sqlite_rebuild_table_batch_for_column_and_primary_key_changes(): void
    {
        $actual = new SchemaSnapshot('sqlite', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INTEGER', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INTEGER', true, null, false, false, 1, 'integer'),
                    new SchemaColumn('status', 'TEXT', true, null, false, false, 2, 'text'),
                ],
                primaryKey: ['user_id'],
                createSql: 'CREATE TABLE memberships (user_id INTEGER PRIMARY KEY, tenant_id INTEGER NULL, status TEXT NULL)',
                indexes: [
                    new SchemaIndex('idx_memberships_status', ['status']),
                ],
            ),
        ]);

        $desired = new SchemaSnapshot('sqlite', [
            new SchemaTable(
                name: 'memberships',
                columns: [
                    new SchemaColumn('user_id', 'INTEGER', false, null, true, false, 0, 'integer'),
                    new SchemaColumn('tenant_id', 'INTEGER', false, null, true, false, 1, 'integer'),
                    new SchemaColumn('status', 'TEXT', false, 'active', false, false, 2, 'text'),
                    new SchemaColumn('created_at', 'TEXT', false, 'CURRENT_TIMESTAMP', false, false, 3, 'timestamp'),
                ],
                primaryKey: ['user_id', 'tenant_id'],
                indexes: [
                    new SchemaIndex('idx_memberships_status', ['status']),
                ],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(1, $actions);
        self::assertSame('rebuild_table', $actions[0]->kind);
        self::assertTrue($actions[0]->requiresNonTransactional);
        self::assertSame([
            'PRAGMA foreign_keys = OFF',
            'ALTER TABLE "memberships" RENAME TO "__vs_rebuild_memberships"',
            'CREATE TABLE "memberships" ("user_id" INTEGER NOT NULL, "tenant_id" INTEGER NOT NULL, "status" TEXT NOT NULL DEFAULT \'active\', "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY ("user_id", "tenant_id"))',
            'INSERT INTO "memberships" ("user_id", "tenant_id", "status") SELECT "user_id", "tenant_id", "status" FROM "__vs_rebuild_memberships"',
            'DROP TABLE "__vs_rebuild_memberships"',
            'CREATE INDEX "idx_memberships_status" ON "memberships" ("status")',
            'PRAGMA foreign_keys = ON',
        ], $actions[0]->sqlBatch);
        self::assertSame([
            'PRAGMA foreign_keys = OFF',
            'ALTER TABLE "memberships" RENAME TO "__vs_rebuild_memberships"',
            'CREATE TABLE "memberships" ("user_id" INTEGER PRIMARY KEY NOT NULL, "tenant_id" INTEGER, "status" TEXT)',
            'INSERT INTO "memberships" ("user_id", "tenant_id", "status") SELECT "user_id", "tenant_id", "status" FROM "__vs_rebuild_memberships"',
            'DROP TABLE "__vs_rebuild_memberships"',
            'CREATE INDEX "idx_memberships_status" ON "memberships" ("status")',
            'PRAGMA foreign_keys = ON',
        ], $actions[0]->rollbackSqlBatch);
    }

    public function test_it_generates_sqlite_rebuild_table_batch_when_columns_must_be_dropped(): void
    {
        $actual = new SchemaSnapshot('sqlite', [
            new SchemaTable(
                name: 'users',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'TEXT', false, null, false, false, 1, 'text'),
                    new SchemaColumn('legacy_code', 'TEXT', true, null, false, false, 2, 'text'),
                ],
                primaryKey: ['id'],
                createSql: 'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, legacy_code TEXT NULL)',
            ),
        ]);

        $desired = new SchemaSnapshot('sqlite', [
            new SchemaTable(
                name: 'users',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'TEXT', false, null, false, false, 1, 'text'),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(1, $actions);
        self::assertSame('rebuild_table', $actions[0]->kind);
        self::assertStringContainsString('dropped columns=legacy_code', $actions[0]->message);
        self::assertSame([
            'PRAGMA foreign_keys = OFF',
            'ALTER TABLE "users" RENAME TO "__vs_rebuild_users"',
            'CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "email" TEXT NOT NULL)',
            'INSERT INTO "users" ("id", "email") SELECT "id", "email" FROM "__vs_rebuild_users"',
            'DROP TABLE "__vs_rebuild_users"',
            'PRAGMA foreign_keys = ON',
        ], $actions[0]->sqlBatch);
    }

    public function test_it_reports_obsolete_columns_and_tables_with_driver_specific_sql(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('legacy_code', 'VARCHAR(32)', true, null, false, false, 2, 'varchar', 32),
                ],
                primaryKey: ['id'],
            ),
            new SchemaTable(
                name: 'legacy_audit',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                ],
                primaryKey: ['id'],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(2, $actions);
        self::assertSame('drop_column', $actions[0]->kind);
        self::assertSame('ALTER TABLE "public"."users" DROP COLUMN "legacy_code"', $actions[0]->sql);
        self::assertSame('ALTER TABLE "public"."users" ADD COLUMN "legacy_code" VARCHAR(32)', $actions[0]->rollbackSql);
        self::assertSame('drop_table', $actions[1]->kind);
        self::assertSame('DROP TABLE "public"."legacy_audit"', $actions[1]->sql);
    }

    public function test_it_reports_obsolete_indexes_and_foreign_keys_with_rollback_sql(): void
    {
        $actual = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('account_id', 'INTEGER', false, null, false, false, 2, 'integer'),
                ],
                primaryKey: ['id'],
                indexes: [
                    new SchemaIndex('idx_users_email_shadow', ['email']),
                ],
                foreignKeys: [
                    new SchemaForeignKey('fk_users_account_id_legacy', ['account_id'], 'accounts_legacy', ['id'], 'public', onDelete: 'RESTRICT'),
                ],
            ),
        ]);

        $desired = new SchemaSnapshot('pgsql', [
            new SchemaTable(
                name: 'users',
                schemaName: 'public',
                columns: [
                    new SchemaColumn('id', 'INTEGER', false, null, true, true, 0, 'integer'),
                    new SchemaColumn('email', 'VARCHAR(255)', false, null, false, false, 1, 'varchar', 255),
                    new SchemaColumn('account_id', 'INTEGER', false, null, false, false, 2, 'integer'),
                ],
                primaryKey: ['id'],
                indexes: [
                    new SchemaIndex('uq_users_email', ['email'], unique: true),
                ],
                foreignKeys: [
                    new SchemaForeignKey('fk_users_account_id', ['account_id'], 'accounts', ['id'], 'public', onDelete: 'CASCADE'),
                ],
            ),
        ]);

        $actions = (new SchemaComparator())->compare($actual, $desired)->actions;

        self::assertCount(4, $actions);
        self::assertSame('drop_foreign_key', $actions[0]->kind);
        self::assertSame('ALTER TABLE "public"."users" DROP CONSTRAINT "fk_users_account_id_legacy"', $actions[0]->sql);
        self::assertSame('ALTER TABLE "public"."users" ADD CONSTRAINT "fk_users_account_id_legacy" FOREIGN KEY ("account_id") REFERENCES "public"."accounts_legacy" ("id") ON DELETE RESTRICT', $actions[0]->rollbackSql);
        self::assertSame('drop_index', $actions[1]->kind);
        self::assertSame('DROP INDEX "public"."idx_users_email_shadow"', $actions[1]->sql);
        self::assertSame('CREATE INDEX "idx_users_email_shadow" ON "public"."users" ("email")', $actions[1]->rollbackSql);
        self::assertSame('create_index', $actions[2]->kind);
        self::assertSame('add_foreign_key', $actions[3]->kind);
    }
}
