<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

use Quantum\Database\Dbal\Contract\ConnectionInterface;

abstract class AbstractMigration implements MigrationInterface
{
    public function description(): string
    {
        return static::class;
    }

    public function isTransactional(): bool
    {
        return true;
    }

    /**
     * @param list<string> $statements
     */
    protected function executeStatements(ConnectionInterface $connection, array $statements): void
    {
        foreach ($statements as $statement) {
            if (trim($statement) === '') {
                continue;
            }

            $connection->executeStatement($statement);
        }
    }
}
