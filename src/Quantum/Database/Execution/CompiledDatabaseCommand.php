<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

use PDO;

final readonly class CompiledDatabaseCommand
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $sql,
        public ?string $connectionName = null,
        public DatabaseResultType $resultType = DatabaseResultType::Rows,
        public int $fetchMode = PDO::FETCH_ASSOC,
        public array $metadata = [],
    ) {
    }
}
