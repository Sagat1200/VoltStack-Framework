<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaDiffAction
{
    public function __construct(
        public string $kind,
        public string $table,
        public ?string $column,
        public string $message,
        public ?string $sql = null,
        public ?string $rollbackSql = null,
        public array $sqlBatch = [],
        public array $rollbackSqlBatch = [],
        public bool $requiresNonTransactional = false,
    ) {}

    /**
     * @return array{kind:string,table:string,column:?string,message:string,sql:?string,rollback_sql:?string,sql_batch:list<string>,rollback_sql_batch:list<string>,requires_non_transactional:bool}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'table' => $this->table,
            'column' => $this->column,
            'message' => $this->message,
            'sql' => $this->sql,
            'rollback_sql' => $this->rollbackSql,
            'sql_batch' => array_values($this->sqlBatch),
            'rollback_sql_batch' => array_values($this->rollbackSqlBatch),
            'requires_non_transactional' => $this->requiresNonTransactional,
        ];
    }
}
