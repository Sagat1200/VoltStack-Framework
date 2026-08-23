<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaDiffReport
{
    /**
     * @param list<SchemaDiffAction> $actions
     */
    public function __construct(
        public SchemaSnapshot $actual,
        public SchemaSnapshot $desired,
        public array $actions,
    ) {}

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /**
     * @return list<string>
     */
    public function sqlStatements(): array
    {
        $sql = [];

        foreach ($this->actions as $action) {
            if ($action->sqlBatch !== []) {
                foreach ($action->sqlBatch as $statement) {
                    if (trim($statement) !== '') {
                        $sql[] = $statement;
                    }
                }
                continue;
            }

            if ($action->sql !== null && trim($action->sql) !== '') {
                $sql[] = $action->sql;
            }
        }

        return $sql;
    }

    /**
     * @return array{actual:array{driver:string,tables:list<array{name:string,primary_key:list<string>,columns:list<array{name:string,type:string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int}>,create_sql:?string}>},desired:array{driver:string,tables:list<array{name:string,primary_key:list<string>,columns:list<array{name:string,type:string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int}>,create_sql:?string}>},actions:list<array{kind:string,table:string,column:?string,message:string,sql:?string,rollback_sql:?string,sql_batch:list<string>,rollback_sql_batch:list<string>,requires_non_transactional:bool}>}
     */
    public function toArray(): array
    {
        return [
            'actual' => $this->actual->toArray(),
            'desired' => $this->desired->toArray(),
            'actions' => array_map(static fn(SchemaDiffAction $action): array => $action->toArray(), $this->actions),
        ];
    }
}
