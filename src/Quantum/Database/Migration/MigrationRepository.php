<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResultType;
use Quantum\Database\Execution\RuntimeBindingSet;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Schema\SchemaManager;

final class MigrationRepository
{
    public function __construct(
        private readonly SchemaManager $schema,
        private readonly QueryExecutorInterface $queries,
        private readonly DatabaseQueryManager $db,
        private readonly string $table = 'quantum_migrations',
    ) {
    }

    public function ensureRepository(): void
    {
        $this->schema->create($this->table, function (\Quantum\Database\Schema\Builder\TableBlueprint $table): void {
            $table->id();
            $table->string('migration')->unique();
            $table->integer('batch');
            $table->string('applied_at');
        }, true);
    }

    /**
     * @return list<string>
     */
    public function appliedNames(): array
    {
        $rows = $this->db->table($this->table)
            ->select('migration')
            ->orderBy('migration')
            ->get()
            ->rows();

        return array_values(array_map(static fn(array $row): string => (string) $row['migration'], $rows));
    }

    public function nextBatchNumber(): int
    {
        $result = $this->queries->execute(new CompiledDatabaseCommand(
            sql: sprintf('SELECT MAX(batch) AS aggregate FROM %s', $this->table),
            resultType: DatabaseResultType::Scalar,
        ));

        return ((int) ($result->scalar() ?? 0)) + 1;
    }

    public function logApplied(string $migration, int $batch): void
    {
        $this->db->table($this->table)->insert([
            'migration' => $migration,
            'batch' => $batch,
            'applied_at' => gmdate('c'),
        ]);
    }

    public function remove(string $migration): void
    {
        $this->db->table($this->table)
            ->where('migration', $migration)
            ->delete();
    }

    /**
     * @return list<string>
     */
    public function lastBatchMigrationNames(): array
    {
        $lastBatch = (int) ($this->queries->execute(new CompiledDatabaseCommand(
            sql: sprintf('SELECT MAX(batch) AS aggregate FROM %s', $this->table),
            resultType: DatabaseResultType::Scalar,
        ))->scalar() ?? 0);

        if ($lastBatch === 0) {
            return [];
        }

        $rows = $this->queries->execute(
            new CompiledDatabaseCommand(
                sql: sprintf('SELECT migration FROM %s WHERE batch = ? ORDER BY id DESC', $this->table),
                resultType: DatabaseResultType::Rows,
            ),
            new RuntimeBindingSet([$lastBatch]),
        )->rows();

        return array_values(array_map(static fn(array $row): string => (string) $row['migration'], $rows));
    }

    public function count(): int
    {
        return (int) ($this->queries->execute(new CompiledDatabaseCommand(
            sql: sprintf('SELECT COUNT(*) AS aggregate FROM %s', $this->table),
            resultType: DatabaseResultType::Scalar,
        ))->scalar() ?? 0);
    }
}
