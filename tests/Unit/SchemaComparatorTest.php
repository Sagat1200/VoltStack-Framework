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
}
