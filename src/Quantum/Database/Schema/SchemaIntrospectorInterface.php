<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

interface SchemaIntrospectorInterface
{
    /**
     * @return list<string>
     */
    public function listTables(): array;

    public function tableExists(string $table): bool;

    public function describeTable(string $table): SchemaTable;

    public function snapshot(): SchemaSnapshot;
}
