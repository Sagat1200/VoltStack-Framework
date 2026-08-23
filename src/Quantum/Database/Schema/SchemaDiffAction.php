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
    ) {}

    /**
     * @return array{kind:string,table:string,column:?string,message:string,sql:?string,rollback_sql:?string}
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
        ];
    }
}